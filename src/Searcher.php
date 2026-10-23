<?php

namespace GlpiPlugin\Globalsearch;

use Change;
use ChangeTask;
use CommonDBTM;
use ITILFollowup;
use KnowbaseItem;
use Problem;
use ProblemTask;
use Ticket;
use TicketTask;

/**
 * Full-text search across ITIL objects (with followups/tasks) and the KB.
 *
 * Strategy: find candidate rows with LIKE queries through GLPI's query
 * builder, then run every candidate through GLPI's own canViewItem()
 * checks so entity, group, private-followup and KB-target rules all apply.
 */
class Searcher
{
    /** Max candidate rows pulled per source table before permission checks. */
    public const MAX_CANDIDATES = 150;

    /** Max results shown per section. */
    public const MAX_RESULTS = 30;

    /** ITIL type => [task class, task FK column] */
    private const ITIL_TYPES = [
        Ticket::class  => [TicketTask::class, 'tickets_id'],
        Problem::class => [ProblemTask::class, 'problems_id'],
        Change::class  => [ChangeTask::class, 'changes_id'],
    ];

    /** @var array<string, CommonDBTM|false> permission-checked parent cache */
    private array $parents = [];

    /** @var string[] */
    private array $terms;

    public function __construct(string $query)
    {
        $this->terms = self::terms($query);
    }

    /** @return string[] */
    public function getTerms(): array
    {
        return $this->terms;
    }

    /**
     * Split the query into up to 6 terms of 2+ characters.
     * Hostnames like web-01.corp.local stay intact (split on whitespace only).
     *
     * @return string[]
     */
    public static function terms(string $query): array
    {
        $parts = preg_split('/\s+/u', trim($query)) ?: [];
        $parts = array_filter($parts, static fn($p) => mb_strlen($p) >= 2);
        return array_slice(array_values(array_unique($parts)), 0, 6);
    }

    /**
     * Run the search.
     *
     * @return array<string, array<int, array{title:string,url:string,matched:string[],snippet:string,date:string}>>
     *         section label => results
     */
    public function search(): array
    {
        if ($this->terms === []) {
            return [];
        }

        $sections = [];
        foreach (self::ITIL_TYPES as $itemtype => [$taskclass, $fk]) {
            if (!$itemtype::canView()) {
                continue;
            }
            $results = $this->searchItil($itemtype, $taskclass, $fk);
            if ($results !== []) {
                $sections[$itemtype::getTypeName(\Session::getPluralNumber())] = $results;
            }
        }

        if (KnowbaseItem::canView()) {
            $results = $this->searchKnowbase();
            if ($results !== []) {
                $sections[KnowbaseItem::getTypeName(1)] = $results;
            }
        }

        return $sections;
    }

    /**
     * Search one ITIL type plus its followups and tasks, merged per parent item.
     */
    private function searchItil(string $itemtype, string $taskclass, string $fk): array
    {
        global $DB;

        /** @var array<int, array> $hits parent id => result */
        $hits = [];

        // 1. Title + description on the item itself.
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'content', 'date_mod'],
            'FROM'   => $itemtype::getTable(),
            'WHERE'  => ['is_deleted' => 0] + $this->likeAll(['name', 'content']),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => self::MAX_CANDIDATES,
        ]);
        foreach ($iterator as $row) {
            $parent = $this->viewableParent($itemtype, (int) $row['id']);
            if ($parent === false) {
                continue;
            }
            $where = $this->matchesAny((string) $row['name']) ? 'title' : 'description';
            $this->addHit($hits, $parent, $where, (string) $row['content'], (string) $row['date_mod']);
        }

        // 2. Followups.
        $iterator = $DB->request([
            'SELECT' => ['id', 'items_id', 'content', 'date_mod'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => ['itemtype' => $itemtype] + $this->likeAll(['content']),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => self::MAX_CANDIDATES,
        ]);
        foreach ($iterator as $row) {
            $fup = new ITILFollowup();
            if (!$fup->getFromDB((int) $row['id']) || !$fup->canViewItem()) {
                continue; // covers private followups
            }
            $parent = $this->viewableParent($itemtype, (int) $row['items_id']);
            if ($parent === false) {
                continue;
            }
            $this->addHit($hits, $parent, 'followup', (string) $row['content'], (string) $row['date_mod']);
        }

        // 3. Tasks.
        $iterator = $DB->request([
            'SELECT' => ['id', $fk, 'content', 'date_mod'],
            'FROM'   => $taskclass::getTable(),
            'WHERE'  => $this->likeAll(['content']),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => self::MAX_CANDIDATES,
        ]);
        foreach ($iterator as $row) {
            /** @var CommonDBTM $task */
            $task = new $taskclass();
            if (!$task->getFromDB((int) $row['id']) || !$task->canViewItem()) {
                continue; // covers private tasks
            }
            $parent = $this->viewableParent($itemtype, (int) $row[$fk]);
            if ($parent === false) {
                continue;
            }
            $this->addHit($hits, $parent, 'task', (string) $row['content'], (string) $row['date_mod']);
        }

        return $this->finalize($hits);
    }

    private function searchKnowbase(): array
    {
        global $DB;

        $hits = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'answer', 'date_mod'],
            'FROM'   => KnowbaseItem::getTable(),
            'WHERE'  => $this->likeAll(['name', 'answer']),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => self::MAX_CANDIDATES,
        ]);
        foreach ($iterator as $row) {
            $kb = new KnowbaseItem();
            if (!$kb->getFromDB((int) $row['id']) || !$kb->canViewItem()) {
                continue; // covers KB visibility targets (users/groups/entities/profiles)
            }
            $where = $this->matchesAny((string) $row['name']) ? 'title' : 'content';
            $this->addHit($hits, $kb, $where, (string) $row['answer'], (string) $row['date_mod']);
        }

        return $this->finalize($hits);
    }

    /**
     * Load a parent item once and keep it only if the current user can see it.
     */
    private function viewableParent(string $itemtype, int $id): CommonDBTM|false
    {
        $key = $itemtype . ':' . $id;
        if (!array_key_exists($key, $this->parents)) {
            /** @var CommonDBTM $item */
            $item = new $itemtype();
            $ok = $item->getFromDB($id)
                && empty($item->fields['is_deleted'])
                && $item->canViewItem();
            $this->parents[$key] = $ok ? $item : false;
        }
        return $this->parents[$key];
    }

    private function addHit(array &$hits, CommonDBTM $item, string $where, string $html, string $date): void
    {
        $id = (int) $item->getID();
        if (!isset($hits[$id])) {
            $hits[$id] = [
                'title'   => sprintf('#%d — %s', $id, (string) ($item->fields['name'] ?? '')),
                'url'     => $item::getFormURLWithID($id),
                'matched' => [],
                'snippet' => '',
                'date'    => '',
            ];
        }
        $hits[$id]['matched'][$where] = true;

        // Prefer a snippet that actually contains a term.
        $snippet = self::snippet($html, $this->terms);
        if ($hits[$id]['snippet'] === '' || (!$this->matchesAny($hits[$id]['snippet']) && $this->matchesAny($snippet))) {
            $hits[$id]['snippet'] = $snippet;
        }
        if ($date > $hits[$id]['date']) {
            $hits[$id]['date'] = $date;
        }
    }

    private function finalize(array $hits): array
    {
        foreach ($hits as &$hit) {
            $hit['matched'] = array_keys($hit['matched']);
        }
        unset($hit);
        usort($hits, static fn($a, $b) => strcmp($b['date'], $a['date']));
        return array_slice($hits, 0, self::MAX_RESULTS);
    }

    /**
     * Every term must appear in at least one of the fields (AND of ORs).
     */
    private function likeAll(array $fields): array
    {
        $and = [];
        foreach ($this->terms as $term) {
            $value = '%' . addcslashes($term, '%_\\') . '%';
            $or = [];
            foreach ($fields as $field) {
                $or[] = [$field => ['LIKE', $value]];
            }
            $and[] = ['OR' => $or];
        }
        return $and;
    }

    private function matchesAny(string $text): bool
    {
        foreach ($this->terms as $term) {
            if (mb_stripos($text, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plain-text excerpt centered on the first matching term.
     */
    public static function snippet(string $html, array $terms, int $radius = 90): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }

        $pos = null;
        foreach ($terms as $term) {
            $p = mb_stripos($text, $term);
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
            }
        }
        $start = max(0, ($pos ?? 0) - $radius);
        $len   = $radius * 2;

        return ($start > 0 ? '…' : '')
            . mb_substr($text, $start, $len)
            . ($start + $len < mb_strlen($text) ? '…' : '');
    }

    /**
     * HTML-escape text and wrap terms in <mark>, in a single pass.
     */
    public static function highlight(string $text, array $terms): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($terms === []) {
            return $escaped;
        }
        $alts = array_map(
            static fn($t) => preg_quote(htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/'),
            $terms
        );
        return (string) preg_replace('/' . implode('|', $alts) . '/iu', '<mark>$0</mark>', $escaped);
    }
}
