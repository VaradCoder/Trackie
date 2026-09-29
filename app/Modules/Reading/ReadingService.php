<?php
/**
 * Reading: library, progress, sessions, notes/quotes, yearly goal, stats.
 *
 * Honesty rules:
 *   - Pages and minutes come ONLY from logged sessions. Marking a book
 *     finished does not invent pages read or reading time.
 *   - "Books this year" = books whose finished_at is in the year.
 *   - Streak = consecutive days with at least one logged session.
 */

require_once __DIR__ . '/OpenLibraryProvider.php';
require_once __DIR__ . '/../../../includes/hobbies.php';

final class ReadingService
{
    public const STATUSES = ['want', 'reading', 'paused', 'finished'];

    public function __construct(private int $uid) {}

    public static function provider(): BookProvider
    {
        return new OpenLibraryProvider();
    }

    /* ── Books ───────────────────────────────────────────────────── */

    public function book(int $id): ?array
    {
        return fetchOne("SELECT * FROM books WHERE id=? AND user_id=?", [$id, $this->uid]) ?: null;
    }

    /** Validated book fields from user input. Throws InvalidArgumentException. */
    private function cleanBook(array $in): array
    {
        $title = trim(sanitizeInput($in['title'] ?? ''));
        if ($title === '') throw new InvalidArgumentException('Title is required.');
        $pages = (int)($in['pages_total'] ?? 0);
        if ($pages < 0 || $pages > 20000) throw new InvalidArgumentException('Page count must be between 1 and 20,000.');
        $cover = trim((string)($in['cover_url'] ?? ''));
        // Only Open Library cover URLs are stored; anything else is dropped.
        if ($cover !== '' && !preg_match('#^https://covers\.openlibrary\.org/b/(id|isbn|olid)/[A-Za-z0-9-]+-[SML]\.jpg$#', $cover)) $cover = '';
        $isbn  = preg_replace('/[^0-9X]/', '', strtoupper((string)($in['isbn'] ?? '')));
        $olKey = preg_match('/^OL\d+W$/', (string)($in['ol_key'] ?? '')) ? $in['ol_key'] : null;
        $year  = (int)($in['publish_year'] ?? 0);
        return [
            'title'        => mb_substr($title, 0, 200),
            'author'       => mb_substr(trim(sanitizeInput($in['author'] ?? '')), 0, 150) ?: null,
            'pages_total'  => $pages ?: null,
            'cover_url'    => $cover ?: null,
            'isbn'         => in_array(strlen($isbn), [10, 13], true) ? $isbn : null,
            'ol_key'       => $olKey,
            'publish_year' => $year > 0 && $year <= (int)date('Y') + 1 ? $year : null,
            'subjects'     => mb_substr(trim(sanitizeInput($in['subjects'] ?? '')), 0, 255) ?: null,
        ];
    }

    public function addBook(array $in): int
    {
        $b = $this->cleanBook($in);
        $status = in_array($in['status'] ?? '', self::STATUSES, true) ? $in['status'] : 'want';
        $today = date('Y-m-d');
        $id = insert(
            "INSERT INTO books (user_id,title,author,pages_total,cover_url,isbn,ol_key,publish_year,subjects,status,started_at,finished_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [$this->uid, $b['title'], $b['author'], $b['pages_total'], $b['cover_url'], $b['isbn'], $b['ol_key'],
             $b['publish_year'], $b['subjects'], $status,
             $status === 'want' ? null : $today, $status === 'finished' ? $today : null]
        );
        if ($status === 'finished') {
            if ($b['pages_total']) update("UPDATE books SET current_page=pages_total WHERE id=?", [$id]);
            awardHobbyXp($this->uid, 'book_finished', 'book', (int)$id);
        }
        return (int)$id;
    }

    public function editBook(int $id, array $in): void
    {
        $book = $this->book($id);
        if (!$book) throw new InvalidArgumentException('Book not found.');
        $b = $this->cleanBook($in);
        // Keep existing metadata the edit form doesn't send.
        foreach (['cover_url', 'isbn', 'ol_key', 'publish_year', 'subjects'] as $k) {
            if ($b[$k] === null && !array_key_exists($k, $in)) $b[$k] = $book[$k];
        }
        $current = array_key_exists('current_page', $in) ? (int)$in['current_page'] : (int)$book['current_page'];
        if ($current < 0) $current = 0;
        if ($b['pages_total'] && $current > $b['pages_total']) {
            throw new InvalidArgumentException("Current page can't be past the last page ({$b['pages_total']}).");
        }
        update(
            "UPDATE books SET title=?, author=?, pages_total=?, current_page=?, cover_url=?, isbn=?, ol_key=?, publish_year=?, subjects=?
              WHERE id=? AND user_id=?",
            [$b['title'], $b['author'], $b['pages_total'], $current, $b['cover_url'], $b['isbn'], $b['ol_key'],
             $b['publish_year'], $b['subjects'], $id, $this->uid]
        );
    }

    /** @return array{xp: ?array} */
    public function setStatus(int $id, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) throw new InvalidArgumentException('Invalid status.');
        $book = $this->book($id);
        if (!$book) throw new InvalidArgumentException('Book not found.');

        $started  = $book['started_at'];
        $finished = $book['finished_at'];
        $current  = (int)$book['current_page'];
        if (in_array($status, ['reading', 'paused', 'finished'], true) && !$started) $started = date('Y-m-d');
        if ($status === 'finished') {
            $finished = $finished ?: date('Y-m-d');
            // Finishing means reaching the last page; pages READ still only count from sessions.
            if ($book['pages_total']) $current = (int)$book['pages_total'];
        } else {
            $finished = null;
        }
        if ($status === 'want') $started = null;

        update("UPDATE books SET status=?, started_at=?, finished_at=?, current_page=? WHERE id=? AND user_id=?",
               [$status, $started, $finished, $current, $id, $this->uid]);

        $xp = $status === 'finished' ? awardHobbyXp($this->uid, 'book_finished', 'book', $id) : null;
        if ($status !== 'finished') undoHobbyActivity($this->uid, 'book_finished', 'book', $id);
        return ['xp' => $xp];
    }

    public function rate(int $id, int $rating): void
    {
        if ($rating < 1 || $rating > 5) throw new InvalidArgumentException('Rating must be 1-5.');
        update("UPDATE books SET rating=? WHERE id=? AND user_id=?", [$rating, $id, $this->uid]);
    }

    public function deleteBook(int $id): void
    {
        delete("DELETE FROM books WHERE id=? AND user_id=?", [$id, $this->uid]);
    }

    public function detail(int $id): ?array
    {
        $book = $this->book($id);
        if (!$book) return null;
        $sessions = fetchAll(
            "SELECT id, session_date, minutes, start_page, end_page, pages, note FROM reading_sessions
              WHERE user_id=? AND book_id=? ORDER BY session_date DESC, id DESC LIMIT 50", [$this->uid, $id]
        );
        $notes = fetchAll(
            "SELECT id, kind, body, page, created_at FROM book_notes WHERE user_id=? AND book_id=? ORDER BY created_at DESC",
            [$this->uid, $id]
        );
        $tot = fetchOne("SELECT COUNT(*) n, COALESCE(SUM(minutes),0) m, COALESCE(SUM(pages),0) p FROM reading_sessions WHERE user_id=? AND book_id=?", [$this->uid, $id]);
        return ['book' => self::present($book), 'sessions' => $sessions, 'notes' => $notes,
                'totals' => ['sessions' => (int)$tot['n'], 'minutes' => (int)$tot['m'], 'pages' => (int)$tot['p']]];
    }

    /** Book row + computed progress % (null when page count is unknown). */
    public static function present(array $b): array
    {
        $total = (int)($b['pages_total'] ?? 0);
        $b['progress'] = $total > 0 ? min(100, (int)round((int)$b['current_page'] / $total * 100)) : null;
        return $b;
    }

    /* ── Sessions ────────────────────────────────────────────────── */

    /**
     * Log a session. end_page is where the reader stopped; pages read =
     * end_page − the book's current page. Returns what happened, including XP.
     */
    public function logSession(int $bookId, int $minutes, ?int $endPage, ?string $note, ?string $date = null): array
    {
        $book = $this->book($bookId);
        if (!$book) throw new InvalidArgumentException('Book not found.');
        if ($minutes < 1 || $minutes > 720) throw new InvalidArgumentException('Minutes must be between 1 and 720.');
        $date = $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        if ($date > date('Y-m-d')) throw new InvalidArgumentException("A session can't be in the future.");
        if ($date < date('Y-m-d', strtotime('-1 year'))) throw new InvalidArgumentException('That date is too far back.');

        $start = (int)$book['current_page'];
        $total = (int)$book['pages_total'];
        if ($endPage !== null) {
            if ($endPage < $start) throw new InvalidArgumentException("You're already on page {$start}; enter the page you stopped at.");
            if ($total && $endPage > $total) throw new InvalidArgumentException("This book has {$total} pages.");
        }
        $pages = $endPage !== null ? $endPage - $start : 0;
        $note  = $note !== null ? mb_substr(trim(sanitizeInput($note)), 0, 500) : null;

        $id = insert(
            "INSERT INTO reading_sessions (user_id,book_id,session_date,minutes,start_page,end_page,pages,note) VALUES (?,?,?,?,?,?,?,?)",
            [$this->uid, $bookId, $date, $minutes, $endPage !== null ? $start : null, $endPage, $pages, $note ?: null]
        );

        $status = $book['status'];
        if ($endPage !== null) update("UPDATE books SET current_page=? WHERE id=?", [$endPage, $bookId]);
        if (in_array($status, ['want', 'paused'], true)) {
            update("UPDATE books SET status='reading', started_at=COALESCE(started_at, ?) WHERE id=?", [$date, $bookId]);
            $status = 'reading';
        }

        $xp = awardHobbyXp($this->uid, 'reading_session', 'reading_day', hobbyDayRef($date));
        return [
            'id'          => (int)$id,
            'minutes'     => $minutes,
            'pages'       => $pages,
            'xp'          => $xp,
            'streak'      => $this->streak(),
            'reached_end' => $total > 0 && $endPage !== null && $endPage >= $total && $status !== 'finished',
        ];
    }

    public function deleteSession(int $id): void
    {
        $s = fetchOne("SELECT session_date FROM reading_sessions WHERE id=? AND user_id=?", [$id, $this->uid]);
        delete("DELETE FROM reading_sessions WHERE id=? AND user_id=?", [$id, $this->uid]);
        // No sessions left that day → the day no longer counts toward the streak.
        if ($s && !fetchOne("SELECT id FROM reading_sessions WHERE user_id=? AND session_date=? LIMIT 1", [$this->uid, $s['session_date']])) {
            undoHobbyActivity($this->uid, 'reading_session', 'reading_day', hobbyDayRef($s['session_date']));
        }
    }

    public function recentSessions(int $limit = 40): array
    {
        return fetchAll(
            "SELECT s.id, s.book_id, s.session_date, s.minutes, s.start_page, s.end_page, s.pages, s.note, b.title, b.cover_url
               FROM reading_sessions s JOIN books b ON b.id=s.book_id
              WHERE s.user_id=? ORDER BY s.session_date DESC, s.id DESC LIMIT " . max(1, min(200, $limit)),
            [$this->uid]
        );
    }

    public function streak(): array
    {
        return hobbyStreak(array_column(fetchAll(
            "SELECT DISTINCT session_date FROM reading_sessions WHERE user_id=?", [$this->uid]
        ), 'session_date'));
    }

    /* ── Notes & quotes ──────────────────────────────────────────── */

    public function addNote(int $bookId, string $kind, string $body, ?int $page): int
    {
        if (!$this->book($bookId)) throw new InvalidArgumentException('Book not found.');
        if (!in_array($kind, ['note', 'quote'], true)) $kind = 'note';
        $body = trim(sanitizeInput($body));
        if ($body === '') throw new InvalidArgumentException('Write something first.');
        if (mb_strlen($body) > 5000) throw new InvalidArgumentException('Keep it under 5,000 characters.');
        return (int)insert(
            "INSERT INTO book_notes (user_id,book_id,kind,body,page) VALUES (?,?,?,?,?)",
            [$this->uid, $bookId, $kind, $body, $page && $page > 0 ? $page : null]
        );
    }

    public function deleteNote(int $id): void
    {
        delete("DELETE FROM book_notes WHERE id=? AND user_id=?", [$id, $this->uid]);
    }

    public function notes(): array
    {
        return fetchAll(
            "SELECT n.id, n.book_id, n.kind, n.body, n.page, n.created_at, b.title, b.author
               FROM book_notes n JOIN books b ON b.id=n.book_id
              WHERE n.user_id=? ORDER BY n.created_at DESC LIMIT 200", [$this->uid]
        );
    }

    /* ── Goals ───────────────────────────────────────────────────── */

    public function goal(int $year): array
    {
        $g = fetchOne("SELECT books_target, pages_target FROM reading_goals WHERE user_id=? AND year=?", [$this->uid, $year]);
        return ['books_target' => $g['books_target'] ?? null, 'pages_target' => $g['pages_target'] ?? null];
    }

    public function saveGoal(int $year, ?int $books, ?int $pages): void
    {
        if ($books !== null && ($books < 1 || $books > 1000)) throw new InvalidArgumentException('Books goal must be 1–1,000.');
        if ($pages !== null && ($pages < 1 || $pages > 1000000)) throw new InvalidArgumentException('Pages goal must be 1–1,000,000.');
        update(
            "INSERT INTO reading_goals (user_id, year, books_target, pages_target) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE books_target=VALUES(books_target), pages_target=VALUES(pages_target)",
            [$this->uid, $year, $books, $pages]
        );
    }

    /* ── Overview + statistics ───────────────────────────────────── */

    public function yearTotals(int $year): array
    {
        $s = fetchOne(
            "SELECT COALESCE(SUM(pages),0) pages, COALESCE(SUM(minutes),0) minutes, COUNT(*) sessions
               FROM reading_sessions WHERE user_id=? AND YEAR(session_date)=?", [$this->uid, $year]
        );
        $books = (int)(fetchOne("SELECT COUNT(*) n FROM books WHERE user_id=? AND status='finished' AND YEAR(finished_at)=?", [$this->uid, $year])['n'] ?? 0);
        return ['books' => $books, 'pages' => (int)$s['pages'], 'minutes' => (int)$s['minutes'], 'sessions' => (int)$s['sessions']];
    }

    /** Minutes read on each of the last $days days, oldest first. */
    public function recentDays(int $days = 7): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $by = array_column(fetchAll(
            "SELECT session_date d, SUM(minutes) m FROM reading_sessions WHERE user_id=? AND session_date>=? GROUP BY session_date",
            [$this->uid, $from]
        ), 'm', 'd');
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $out[] = ['date' => $d, 'minutes' => (int)($by[$d] ?? 0)];
        }
        return $out;
    }

    /** Books on the Reading shelf with their last session. */
    public function currentlyReading(): array
    {
        $books = fetchAll(
            "SELECT b.*, (SELECT MAX(s.session_date) FROM reading_sessions s WHERE s.book_id=b.id) last_session,
                    (SELECT s.minutes FROM reading_sessions s WHERE s.book_id=b.id ORDER BY s.session_date DESC, s.id DESC LIMIT 1) last_minutes
               FROM books b WHERE b.user_id=? AND b.status='reading'
              ORDER BY COALESCE(last_session, b.started_at, DATE(b.created_at)) DESC", [$this->uid]
        );
        return array_map([self::class, 'present'], $books);
    }

    public function stats(int $year): array
    {
        $months = array_fill(1, 12, ['books' => 0, 'pages' => 0, 'minutes' => 0]);
        foreach (fetchAll(
            "SELECT MONTH(session_date) m, SUM(pages) p, SUM(minutes) t FROM reading_sessions
              WHERE user_id=? AND YEAR(session_date)=? GROUP BY MONTH(session_date)", [$this->uid, $year]
        ) as $r) { $months[(int)$r['m']]['pages'] = (int)$r['p']; $months[(int)$r['m']]['minutes'] = (int)$r['t']; }
        foreach (fetchAll(
            "SELECT MONTH(finished_at) m, COUNT(*) n FROM books WHERE user_id=? AND status='finished' AND YEAR(finished_at)=? GROUP BY MONTH(finished_at)",
            [$this->uid, $year]
        ) as $r) { $months[(int)$r['m']]['books'] = (int)$r['n']; }

        $authors = fetchAll(
            "SELECT author, COUNT(*) n FROM books WHERE user_id=? AND status='finished' AND author IS NOT NULL AND author<>''
              GROUP BY author ORDER BY n DESC, author LIMIT 6", [$this->uid]
        );
        $subjects = [];
        foreach (fetchAll("SELECT subjects FROM books WHERE user_id=? AND status IN ('finished','reading') AND subjects IS NOT NULL", [$this->uid]) as $r) {
            foreach (array_filter(array_map('trim', explode(',', $r['subjects']))) as $s) $subjects[$s] = ($subjects[$s] ?? 0) + 1;
        }
        arsort($subjects);

        $years = array_map('intval', array_column(fetchAll(
            "SELECT DISTINCT YEAR(session_date) y FROM reading_sessions WHERE user_id=?
             UNION SELECT DISTINCT YEAR(finished_at) FROM books WHERE user_id=? AND finished_at IS NOT NULL",
            [$this->uid, $this->uid]
        ), 'y'));
        $years[] = (int)date('Y');
        $years = array_values(array_unique($years));
        rsort($years);

        $totals = $this->yearTotals($year);
        return [
            'year'     => $year,
            'years'    => $years,
            'totals'   => $totals,
            'avg_session' => $totals['sessions'] ? (int)round($totals['minutes'] / $totals['sessions']) : 0,
            'months'   => array_values($months),
            'authors'  => $authors,
            'subjects' => array_map(static fn($k, $v) => ['subject' => $k, 'books' => $v], array_keys(array_slice($subjects, 0, 8, true)), array_slice($subjects, 0, 8, true)),
            'streak'   => $this->streak(),
            'goal'     => $this->goal($year),
        ];
    }
}
