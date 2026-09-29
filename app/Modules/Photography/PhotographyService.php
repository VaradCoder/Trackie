<?php
/**
 * Photography: photos (uploads + EXIF), shoots, projects, gear, editing
 * queue, monthly goals, stats.
 *
 * Honesty rules:
 *   - EXIF fields are stored only when read from the file (by the browser
 *     from the original, or by the server). Missing = NULL = "Not available".
 *   - "Shooting time" is the duration the user entered on shoots, nothing else.
 *   - A photo's date is its EXIF capture date; without one it's the upload
 *     date, and the UI says so.
 */

require_once __DIR__ . '/PhotoStorage.php';
require_once __DIR__ . '/../../../includes/hobbies.php';

final class PhotographyService
{
    public const MAX_PHOTOS = 1000;   // per user — shared hosting has finite disk
    public const EDIT = ['raw', 'editing', 'edited'];
    public const GEAR = ['camera', 'lens', 'accessory'];

    public function __construct(private int $uid) {}

    /* ── Ownership helpers ───────────────────────────────────────── */

    private function ownId(string $table, $id): ?int
    {
        $id = (int)$id;
        if ($id <= 0) return null;
        $ok = fetchOne("SELECT id FROM {$table} WHERE id=? AND user_id=?", [$id, $this->uid]);
        if (!$ok) throw new InvalidArgumentException('That item no longer exists.');
        return $id;
    }

    private static function str($v, int $max): ?string
    {
        $v = trim(sanitizeInput((string)($v ?? '')));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function date($v): ?string
    {
        $v = (string)($v ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) return null;
        if ($v > date('Y-m-d', strtotime('+1 day'))) throw new InvalidArgumentException("The date can't be in the future.");
        return $v;
    }

    /** Validate EXIF values the browser read from the original file. */
    public static function cleanExif(array $in): array
    {
        $out = [];
        if ($c = self::str($in['camera'] ?? null, 100)) $out['camera'] = $c;
        if ($l = self::str($in['lens'] ?? null, 100)) $out['lens'] = $l;
        $iso = (int)($in['iso'] ?? 0);
        if ($iso >= 1 && $iso <= 819200) $out['iso'] = $iso;
        $sh = trim((string)($in['shutter'] ?? ''));
        if (preg_match('#^(1/\d{1,5}|\d{1,4}(\.\d)?)$#', $sh)) $out['shutter'] = $sh;
        $ap = (float)($in['aperture'] ?? 0);
        if ($ap >= 0.5 && $ap <= 128) $out['aperture'] = round($ap, 1);
        $fl = (float)($in['focal_mm'] ?? 0);
        if ($fl >= 1 && $fl <= 5000) $out['focal_mm'] = round($fl, 1);
        $ta = (string)($in['taken_at'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ta) && ($ts = strtotime($ta))
            && $ts > strtotime('1990-01-01') && $ts < time() + 86400) $out['taken_at'] = $ta;
        return $out;
    }

    /* ── Photos ──────────────────────────────────────────────────── */

    public function upload(array $file, array $meta): array
    {
        $count = (int)(fetchOne("SELECT COUNT(*) n FROM photo_images WHERE user_id=?", [$this->uid])['n'] ?? 0);
        if ($count >= self::MAX_PHOTOS) throw new InvalidArgumentException('You\'ve reached the ' . self::MAX_PHOTOS . '-photo limit. Delete some to upload more.');
        $shoot   = $this->ownId('photos', $meta['shoot_id'] ?? 0);
        $project = $this->ownId('photo_projects', $meta['project_id'] ?? 0);

        $stored = PhotoStorage::store($this->uid, $file);
        // Server-read EXIF wins; otherwise what the browser read from the original.
        $server = $stored['exif'];
        $client = self::cleanExif($meta);
        $exif   = $server ?: $client;
        $source = $server ? 'server' : ($client ? 'browser' : null);

        $id = (int)insert(
            "INSERT INTO photo_images (user_id,shoot_id,project_id,file,thumb,width,height,bytes,title,taken_at,camera,lens,iso,shutter,aperture,focal_mm,exif_source)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$this->uid, $shoot, $project, $stored['file'], $stored['thumb'], $stored['width'], $stored['height'], $stored['bytes'],
             self::str($meta['title'] ?? null, 150), $exif['taken_at'] ?? null, $exif['camera'] ?? null, $exif['lens'] ?? null,
             $exif['iso'] ?? null, $exif['shutter'] ?? null, $exif['aperture'] ?? null, $exif['focal_mm'] ?? null, $source]
        );
        $xp = awardHobbyXp($this->uid, 'photo_upload_day', 'photo_day', hobbyDayRef());
        return ['id' => $id, 'xp' => $xp, 'photo' => $this->photo($id)];
    }

    public function photo(int $id): ?array
    {
        return fetchOne(
            "SELECT i.*, s.title shoot_title, p.name project_name FROM photo_images i
               LEFT JOIN photos s ON s.id=i.shoot_id LEFT JOIN photo_projects p ON p.id=i.project_id
              WHERE i.id=? AND i.user_id=?", [$id, $this->uid]
        ) ?: null;
    }

    public function photos(): array
    {
        return fetchAll(
            "SELECT id, shoot_id, project_id, thumb, width, height, title, taken_at, created_at, favorite, edit_status
               FROM photo_images WHERE user_id=? ORDER BY COALESCE(taken_at, created_at) DESC, id DESC LIMIT " . self::MAX_PHOTOS,
            [$this->uid]
        );
    }

    public function updatePhoto(int $id, array $in): void
    {
        $p = $this->photo($id);
        if (!$p) throw new InvalidArgumentException('Photo not found.');
        $set = []; $args = [];
        if (array_key_exists('title', $in))      { $set[] = 'title=?';   $args[] = self::str($in['title'], 150); }
        if (array_key_exists('caption', $in))    { $set[] = 'caption=?'; $args[] = self::str($in['caption'], 1000); }
        if (array_key_exists('shoot_id', $in))   { $set[] = 'shoot_id=?';   $args[] = $this->ownId('photos', $in['shoot_id']); }
        if (array_key_exists('project_id', $in)) { $set[] = 'project_id=?'; $args[] = $this->ownId('photo_projects', $in['project_id']); }
        if (array_key_exists('favorite', $in))   { $set[] = 'favorite=?';   $args[] = (int)!empty($in['favorite']); }
        if (array_key_exists('edit_status', $in)) {
            if (!in_array($in['edit_status'], self::EDIT, true)) throw new InvalidArgumentException('Invalid edit status.');
            $set[] = 'edit_status=?'; $args[] = $in['edit_status'];
        }
        if (!$set) return;
        $args[] = $id; $args[] = $this->uid;
        update("UPDATE photo_images SET " . implode(',', $set) . " WHERE id=? AND user_id=?", $args);
    }

    public function deletePhoto(int $id): void
    {
        $p = $this->photo($id);
        if (!$p) return;
        delete("DELETE FROM photo_images WHERE id=? AND user_id=?", [$id, $this->uid]);
        PhotoStorage::delete($this->uid, $p['file'], $p['thumb']);
    }

    /* ── Shoots (the original `photos` table) ────────────────────── */

    public function saveShoot(array $in, ?int $id = null): int
    {
        $title = self::str($in['title'] ?? null, 150);
        if (!$title) throw new InvalidArgumentException('Give the shoot a name.');
        $dur = trim((string)($in['duration_min'] ?? '')) === '' ? null : (int)$in['duration_min'];
        if ($dur !== null && ($dur < 1 || $dur > 1440)) throw new InvalidArgumentException('Duration must be 1–1,440 minutes.');
        $status = in_array($in['status'] ?? '', ['to_edit', 'edited'], true) ? $in['status'] : 'to_edit';
        $vals = [$title, self::str($in['location'] ?? null, 150), self::str($in['camera'] ?? null, 100), $status,
                 self::date($in['taken_date'] ?? null) ?? date('Y-m-d'), $dur, self::str($in['notes'] ?? null, 1000),
                 $this->ownId('photo_projects', $in['project_id'] ?? 0)];
        if ($id) {
            $this->ownId('photos', $id);
            update("UPDATE photos SET title=?,location=?,camera=?,status=?,taken_date=?,duration_min=?,notes=?,project_id=? WHERE id=? AND user_id=?",
                   [...$vals, $id, $this->uid]);
        } else {
            $id = (int)insert("INSERT INTO photos (title,location,camera,status,taken_date,duration_min,notes,project_id,user_id) VALUES (?,?,?,?,?,?,?,?,?)",
                              [...$vals, $this->uid]);
        }
        return $id;
    }

    public function shootXp(int $id): ?array
    {
        return awardHobbyXp($this->uid, 'photo_shoot', 'shoot', $id);
    }

    public function setShootStatus(int $id, string $status): void
    {
        if (!in_array($status, ['to_edit', 'edited'], true)) throw new InvalidArgumentException('Invalid status.');
        update("UPDATE photos SET status=? WHERE id=? AND user_id=?", [$status, $id, $this->uid]);
    }

    public function deleteShoot(int $id): void
    {
        // Photos in the shoot are kept (shoot_id → NULL via FK).
        delete("DELETE FROM photos WHERE id=? AND user_id=?", [$id, $this->uid]);
    }

    public function shoots(): array
    {
        return fetchAll(
            "SELECT s.*, pr.name project_name, (SELECT COUNT(*) FROM photo_images i WHERE i.shoot_id=s.id) photo_count
               FROM photos s LEFT JOIN photo_projects pr ON pr.id=s.project_id
              WHERE s.user_id=? ORDER BY COALESCE(s.taken_date, DATE(s.created_at)) DESC, s.id DESC", [$this->uid]
        );
    }

    /* ── Projects ────────────────────────────────────────────────── */

    public function saveProject(array $in, ?int $id = null): int
    {
        $name = self::str($in['name'] ?? null, 120);
        if (!$name) throw new InvalidArgumentException('Give the project a name.');
        $status = ($in['status'] ?? '') === 'done' ? 'done' : 'active';
        $desc = self::str($in['description'] ?? null, 500);
        if ($id) {
            $this->ownId('photo_projects', $id);
            update("UPDATE photo_projects SET name=?, description=?, status=? WHERE id=? AND user_id=?", [$name, $desc, $status, $id, $this->uid]);
            return $id;
        }
        return (int)insert("INSERT INTO photo_projects (user_id,name,description,status) VALUES (?,?,?,?)", [$this->uid, $name, $desc, $status]);
    }

    public function deleteProject(int $id): void
    {
        update("UPDATE photos SET project_id=NULL WHERE project_id=? AND user_id=?", [$id, $this->uid]);
        delete("DELETE FROM photo_projects WHERE id=? AND user_id=?", [$id, $this->uid]);
    }

    public function projects(): array
    {
        return fetchAll(
            "SELECT p.*, (SELECT COUNT(*) FROM photo_images i WHERE i.project_id=p.id) photo_count,
                    (SELECT i.id FROM photo_images i WHERE i.project_id=p.id ORDER BY i.favorite DESC, i.id DESC LIMIT 1) cover_id
               FROM photo_projects p WHERE p.user_id=? ORDER BY p.status='done', p.created_at DESC", [$this->uid]
        );
    }

    /* ── Gear ────────────────────────────────────────────────────── */

    public function addGear(string $kind, $name, $notes): int
    {
        if (!in_array($kind, self::GEAR, true)) throw new InvalidArgumentException('Pick camera, lens or accessory.');
        $name = self::str($name, 100);
        if (!$name) throw new InvalidArgumentException('Enter a name.');
        return (int)insert("INSERT INTO photo_gear (user_id,kind,name,notes) VALUES (?,?,?,?)", [$this->uid, $kind, $name, self::str($notes, 255)]);
    }

    public function deleteGear(int $id): void
    {
        delete("DELETE FROM photo_gear WHERE id=? AND user_id=?", [$id, $this->uid]);
    }

    /** Gear list + how many photos' EXIF name each camera/lens (case-insensitive match). */
    public function gear(): array
    {
        $rows = fetchAll("SELECT id, kind, name, notes FROM photo_gear WHERE user_id=? ORDER BY kind, name", [$this->uid]);
        foreach ($rows as &$g) {
            $g['photo_count'] = null;
            if ($g['kind'] === 'accessory') continue;
            $col = $g['kind'] === 'camera' ? 'camera' : 'lens';
            $g['photo_count'] = (int)(fetchOne(
                "SELECT COUNT(*) n FROM photo_images WHERE user_id=? AND {$col} LIKE ?",
                [$this->uid, '%' . addcslashes($g['name'], '%_\\') . '%']
            )['n'] ?? 0);
        }
        return $rows;
    }

    /* ── Goals ───────────────────────────────────────────────────── */

    public function goals(): array
    {
        $g = fetchOne("SELECT photos_per_month, shoots_per_month FROM photo_goals WHERE user_id=?", [$this->uid]);
        return ['photos_per_month' => $g['photos_per_month'] ?? null, 'shoots_per_month' => $g['shoots_per_month'] ?? null];
    }

    public function saveGoals(?int $photos, ?int $shoots): void
    {
        if ($photos !== null && ($photos < 1 || $photos > 10000)) throw new InvalidArgumentException('Photos goal must be 1–10,000.');
        if ($shoots !== null && ($shoots < 1 || $shoots > 100)) throw new InvalidArgumentException('Shoots goal must be 1–100.');
        update("INSERT INTO photo_goals (user_id,photos_per_month,shoots_per_month) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE photos_per_month=VALUES(photos_per_month), shoots_per_month=VALUES(shoots_per_month)",
               [$this->uid, $photos, $shoots]);
    }

    /* ── Overview + stats ────────────────────────────────────────── */

    public function streak(): array
    {
        $dates = array_merge(
            array_column(fetchAll("SELECT DISTINCT COALESCE(taken_date, DATE(created_at)) d FROM photos WHERE user_id=?", [$this->uid]), 'd'),
            array_column(fetchAll("SELECT DISTINCT DATE(COALESCE(taken_at, created_at)) d FROM photo_images WHERE user_id=?", [$this->uid]), 'd')
        );
        return hobbyStreak(array_filter($dates, static fn($d) => $d && $d <= date('Y-m-d')));
    }

    public function month(): array
    {
        $from = date('Y-m-01');
        return [
            'photos'  => (int)fetchOne("SELECT COUNT(*) n FROM photo_images WHERE user_id=? AND COALESCE(taken_at, created_at) >= ?", [$this->uid, $from])['n'],
            'shoots'  => (int)fetchOne("SELECT COUNT(*) n FROM photos WHERE user_id=? AND COALESCE(taken_date, DATE(created_at)) >= ?", [$this->uid, $from])['n'],
            'minutes' => (int)fetchOne("SELECT COALESCE(SUM(duration_min),0) n FROM photos WHERE user_id=? AND COALESCE(taken_date, DATE(created_at)) >= ?", [$this->uid, $from])['n'],
            'active_projects' => (int)fetchOne("SELECT COUNT(*) n FROM photo_projects WHERE user_id=? AND status='active'", [$this->uid])['n'],
            'to_edit' => (int)fetchOne("SELECT COUNT(*) n FROM photo_images WHERE user_id=? AND edit_status<>'edited'", [$this->uid])['n'],
        ];
    }

    public function stats(int $year): array
    {
        $months = array_fill(1, 12, ['photos' => 0, 'shoots' => 0, 'minutes' => 0]);
        foreach (fetchAll("SELECT MONTH(COALESCE(taken_at, created_at)) m, COUNT(*) n FROM photo_images
                            WHERE user_id=? AND YEAR(COALESCE(taken_at, created_at))=? GROUP BY m", [$this->uid, $year]) as $r) {
            $months[(int)$r['m']]['photos'] = (int)$r['n'];
        }
        foreach (fetchAll("SELECT MONTH(COALESCE(taken_date, DATE(created_at))) m, COUNT(*) n, COALESCE(SUM(duration_min),0) t FROM photos
                            WHERE user_id=? AND YEAR(COALESCE(taken_date, DATE(created_at)))=? GROUP BY m", [$this->uid, $year]) as $r) {
            $months[(int)$r['m']]['shoots'] = (int)$r['n'];
            $months[(int)$r['m']]['minutes'] = (int)$r['t'];
        }
        $total = (int)fetchOne("SELECT COUNT(*) n FROM photo_images WHERE user_id=?", [$this->uid])['n'];
        $top = function (string $expr, string $where = '1') {
            return fetchAll("SELECT {$expr} v, COUNT(*) n FROM photo_images WHERE user_id=? AND {$where} GROUP BY v ORDER BY n DESC, v LIMIT 6", [$this->uid]);
        };
        $withExif = (int)fetchOne("SELECT COUNT(*) n FROM photo_images WHERE user_id=? AND exif_source IS NOT NULL", [$this->uid])['n'];

        $years = array_map('intval', array_column(fetchAll(
            "SELECT DISTINCT YEAR(COALESCE(taken_at, created_at)) y FROM photo_images WHERE user_id=?
             UNION SELECT DISTINCT YEAR(COALESCE(taken_date, DATE(created_at))) FROM photos WHERE user_id=?", [$this->uid, $this->uid]), 'y'));
        $years[] = (int)date('Y');
        $years = array_values(array_unique(array_filter($years)));
        rsort($years);

        return [
            'year'   => $year, 'years' => $years,
            'months' => array_values($months),
            'totals' => [
                'photos'    => array_sum(array_column($months, 'photos')),
                'shoots'    => array_sum(array_column($months, 'shoots')),
                'minutes'   => array_sum(array_column($months, 'minutes')),
                'all_photos'=> $total,
                'favorites' => (int)fetchOne("SELECT COUNT(*) n FROM photo_images WHERE user_id=? AND favorite=1", [$this->uid])['n'],
                'with_exif' => $withExif,
            ],
            'cameras'   => $top('camera', 'camera IS NOT NULL'),
            'lenses'    => $top('lens', 'lens IS NOT NULL'),
            'focal'     => $top("CONCAT(ROUND(focal_mm), 'mm')", 'focal_mm IS NOT NULL'),
            'apertures' => $top("CONCAT('f/', TRIM(TRAILING '.0' FROM aperture))", 'aperture IS NOT NULL'),
            'iso'       => $top("CASE WHEN iso<=200 THEN 'ISO ≤200' WHEN iso<=800 THEN 'ISO 201–800' WHEN iso<=3200 THEN 'ISO 801–3200' ELSE 'ISO >3200' END", 'iso IS NOT NULL'),
            'edit'      => array_column(fetchAll("SELECT edit_status v, COUNT(*) n FROM photo_images WHERE user_id=? GROUP BY edit_status", [$this->uid]), 'n', 'v'),
            'streak'    => $this->streak(),
        ];
    }
}
