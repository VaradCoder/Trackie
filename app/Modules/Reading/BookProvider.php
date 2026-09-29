<?php
/**
 * Book metadata provider. Only catalogue data comes from a provider (title,
 * author, cover, ISBN, pages, year, subjects). The user's own tracking —
 * progress, sessions, notes, rating, dates — always lives in Trackie's DB,
 * so the Reading pages keep working if the provider is down.
 */
interface BookProvider
{
    /**
     * @return ?array list of results; null when the provider could not be reached.
     *   Each result: [title, author, pages_total, cover_url, isbn, ol_key, publish_year, subjects]
     */
    public function search(string $query, int $limit = 8): ?array;
}
