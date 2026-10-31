<?php
// includes/rag.php   [M4]  Retrieval-augmented answers over STATIC knowledge.
//
// Pipeline: ingest (knowledge/*.md, starter README, entity cards from the current dataset)
//   -> preprocess (strip markdown noise) -> chunk by heading (~1,200 chars)
//   -> embed (optional, OpenAI-compatible) -> store in kb_chunks (MySQL, FULLTEXT index)
//   -> retrieve (cosine in PHP over visible chunks; FULLTEXT when embeddings are unavailable)
//   -> context with numbered sources [S1].. -> LLM answer that must cite them.
// Live facts (who has which room now) never come from here; chat.php queries the tables.
declare(strict_types=1);

require_once __DIR__ . '/ai.php';

const CIT_CHUNK_CHARS = 1200;

function cit_rag_clean(string $md): string
{
    $md = preg_replace('/```.*?```/s', '', $md);              // code blocks carry no policy text
    $md = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $md);  // links -> text
    $md = str_replace(['**', '`'], '', $md);
    return trim(preg_replace("/\n{3,}/", "\n\n", $md));
}

/** Split markdown by headings, then by paragraphs so no chunk is much over CIT_CHUNK_CHARS. */
function cit_rag_chunk(string $md): array
{
    $out = [];
    $section = null;
    $buf = '';
    $flush = function () use (&$out, &$buf, &$section) {
        $text = trim($buf);
        if ($text !== '') $out[] = ['section' => $section, 'content' => $text];
        $buf = '';
    };
    foreach (preg_split('/\R/', $md) as $line) {
        if (preg_match('/^#{1,4}\s+(.*)$/', $line, $m)) {
            $flush();
            $section = trim($m[1]);
            continue;
        }
        if (mb_strlen($buf) + mb_strlen($line) > CIT_CHUNK_CHARS && trim($line) === '') {
            $flush();
            continue;
        }
        $buf .= $line . "\n";
    }
    $flush();
    return $out;
}

function cit_rag_sources(PDO $db): array
{
    $src = [];
    foreach (glob(cit_path('knowledge/*.md')) as $file) {
        foreach (cit_rag_chunk(cit_rag_clean(file_get_contents($file))) as $c) {
            $src[] = ['doc', 'knowledge/' . basename($file), $c['section'], 'all', $c['content']];
        }
    }
    $readme = cit_path('data/starter/STARTER_README.md');
    if (is_file($readme)) {
        foreach (cit_rag_chunk(cit_rag_clean(file_get_contents($readme))) as $c) {
            $src[] = ['doc', 'starter README', $c['section'], 'all', $c['content']];
        }
    }
    // Entity cards from the current dataset. Faculty cards hold public facts only
    // (name, department), never unavailability reasons or preferences.
    foreach ($db->query('SELECT * FROM rooms ORDER BY ordinal') as $r) {
        $f = implode(', ', array_map(fn($x) => str_replace('_', ' ', $x), json_decode($r['features'], true))) ?: 'no special equipment';
        $u = implode(', ', json_decode($r['allowed_uses'], true));
        $src[] = ['entity', 'room:' . $r['id'], "Room {$r['id']}", 'all',
            "{$r['id']} is {$r['name']} in {$r['building']}: a " . str_replace('_', ' ', $r['type']) . " seating {$r['capacity']} ({$r['capacity']}/2 in exams). "
            . "Equipment: $f. " . ($r['accessible'] ? 'Step-free (wheelchair) accessible.' : 'Not wheelchair accessible.') . " Can be used for: $u."];
    }
    foreach ($db->query('SELECT c.*, GROUP_CONCAT(DISTINCT f.name ORDER BY f.name SEPARATOR ", ") AS teachers
                         FROM courses c LEFT JOIN sessions s ON s.course_id = c.id LEFT JOIN faculty f ON f.id = s.faculty_id
                         GROUP BY c.id ORDER BY c.ordinal') as $c) {
        $src[] = ['entity', 'course:' . $c['id'], "Course {$c['id']}", 'all',
            "{$c['id']} {$c['title']} ({$c['department']}, year {$c['year']}): {$c['lecture_hours']} lecture hours, {$c['tutorial_hours']} tutorial hours and {$c['lab_blocks']} lab blocks a week. Taught by " . ($c['teachers'] ?: 'unassigned') . '.'];
    }
    foreach ($db->query('SELECT * FROM student_groups ORDER BY ordinal') as $g) {
        $src[] = ['entity', 'group:' . $g['id'], "Group {$g['id']}", 'all',
            "{$g['id']} is {$g['name']} ({$g['department']}, year {$g['year']}) with {$g['size']} students." . ($g['needs_accessible'] ? ' Needs step-free rooms.' : '')];
    }
    foreach ($db->query('SELECT id, name, department FROM faculty ORDER BY ordinal') as $f) {
        $src[] = ['entity', 'faculty:' . $f['id'], "Faculty {$f['id']}", 'all', "{$f['id']} is {$f['name']}, {$f['department']} department."];
    }
    return $src;
}

function cit_pack_vec(array $v): string { return pack('g*', ...array_map('floatval', $v)); }
function cit_unpack_vec(string $b): array { return array_values(unpack('g*', $b)); }

function cit_rag_ingest(PDO $db, ?int $userId = null): array
{
    $src = cit_rag_sources($db);
    $db->exec('DELETE FROM kb_chunks');
    $ins = $db->prepare('INSERT IGNORE INTO kb_chunks (source_type, source_ref, section, visibility, content, content_sha256, token_estimate,
                         embedding, embedding_dim, embedding_model) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $embedded = 0;
    foreach (array_chunk($src, 64) as $batch) {
        $vecs = cit_embed(array_map(fn($s) => ($s[2] ? $s[2] . "\n" : '') . $s[4], $batch), $userId);
        foreach ($batch as $i => [$type, $ref, $section, $vis, $content]) {
            $v = $vecs[$i] ?? null;
            $ins->execute([$type, $ref, $section, $vis, $content, hash('sha256', $content), (int)ceil(mb_strlen($content) / 4),
                $v ? cit_pack_vec($v) : null, $v ? count($v) : null, $v ? cit_env('EMBED_MODEL') : null]);
            $embedded += $v ? 1 : 0;
        }
    }
    return ['chunks' => count($src), 'embedded' => $embedded, 'mode' => $embedded ? 'vector + keyword' : 'keyword only'];
}

function cit_rag_visible(string $role): array
{
    return $role === 'admin' ? ['all', 'staff', 'admin'] : (in_array($role, ['department_coordinator', 'faculty'], true) ? ['all', 'staff'] : ['all']);
}

/** Top-k chunks for $query that $role may see. */
function cit_rag_search(PDO $db, string $query, string $role, int $k = 5, ?int $userId = null): array
{
    $vis = cit_rag_visible($role);
    $in = implode(',', array_fill(0, count($vis), '?'));
    $hits = [];
    $qv = (int)$db->query('SELECT COUNT(*) FROM kb_chunks WHERE embedding IS NOT NULL')->fetchColumn() > 0 ? (cit_embed([$query], $userId)[0] ?? null) : null;
    if ($qv) {
        $qn = sqrt(array_sum(array_map(fn($x) => $x * $x, $qv))) ?: 1;
        $st = $db->prepare("SELECT id, source_ref, section, content, embedding FROM kb_chunks WHERE visibility IN ($in) AND embedding IS NOT NULL");
        $st->execute($vis);
        foreach ($st as $r) {
            $v = cit_unpack_vec($r['embedding']);
            if (count($v) !== count($qv)) continue;
            $dot = 0.0; $n = 0.0;
            foreach ($v as $i => $x) { $dot += $x * $qv[$i]; $n += $x * $x; }
            $score = $dot / ($qn * (sqrt($n) ?: 1));
            if ($score >= 0.25) $hits[] = ['id' => (int)$r['id'], 'source_ref' => $r['source_ref'], 'section' => $r['section'], 'content' => $r['content'], 'score' => round($score, 3)];
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        $hits = array_slice($hits, 0, $k);
    }
    if (!$hits) {   // keyword fallback: FULLTEXT, then LIKE for short terms (FULLTEXT ignores words under 3 letters)
        $terms = array_filter(preg_split('/[^\p{L}\p{N}\-]+/u', mb_strtolower($query)), fn($w) => mb_strlen($w) >= 2);
        $stop = ['the', 'is', 'a', 'an', 'what', 'how', 'can', 'i', 'do', 'to', 'of', 'in', 'for', 'and', 'kya', 'hai', 'ka', 'ki', 'ke', 'me', 'mein', 'are', 'my', 'who'];
        $terms = array_values(array_diff($terms, $stop));
        if ($terms) {
            $st = $db->prepare("SELECT id, source_ref, section, content, MATCH(section, content) AGAINST (? IN NATURAL LANGUAGE MODE) AS score
                                FROM kb_chunks WHERE visibility IN ($in) AND MATCH(section, content) AGAINST (? IN NATURAL LANGUAGE MODE)
                                ORDER BY score DESC LIMIT $k");
            $q = implode(' ', $terms);
            $st->execute(array_merge([$q], $vis, [$q]));
            foreach ($st as $r) $hits[] = ['id' => (int)$r['id'], 'source_ref' => $r['source_ref'], 'section' => $r['section'], 'content' => $r['content'], 'score' => round((float)$r['score'], 3)];
            if (!$hits) {
                $like = implode(' OR ', array_fill(0, count($terms), 'content LIKE ?'));
                $st = $db->prepare("SELECT id, source_ref, section, content FROM kb_chunks WHERE visibility IN ($in) AND ($like) LIMIT $k");
                $st->execute(array_merge($vis, array_map(fn($t) => '%' . $t . '%', $terms)));
                foreach ($st as $r) $hits[] = $r + ['score' => null];
            }
        }
    }
    return $hits;
}

/** Grounded answer with citations. Without an LLM, returns the best passages verbatim. */
function cit_rag_answer(PDO $db, string $question, array $user, array $history = [], ?string $live = null): array
{
    $hits = cit_rag_search($db, $question, $user['role'], 5, (int)$user['id']);
    $sources = array_map(fn($h, $i) => ['n' => $i + 1, 'source' => $h['source_ref'], 'section' => $h['section']], $hits, array_keys($hits));
    if (!$hits && $live === null) {
        return ['reply' => "I couldn't find that in CIT's scheduling data. Try asking about rooms, timetables, bookings or the booking rules.", 'sources' => [], 'used_llm' => false];
    }
    $ctx = implode("\n\n", array_map(fn($h, $i) => '[S' . ($i + 1) . "] ({$h['source_ref']}" . ($h['section'] ? " / {$h['section']}" : '') . ")\n" . $h['content'], $hits, array_keys($hits)));
    if ($live) $ctx .= "\n\n[LIVE DATA]\n$live";
    $msgs = array_slice(array_values(array_filter($history, fn($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_string($m['content'] ?? null))), -6);
    $msgs[] = ['role' => 'user', 'content' => "Sources:\n$ctx\n\nQuestion: $question"];
    $text = cit_llm_chat(file_get_contents(cit_path('prompts/rag_answer.txt')), $msgs, 'rag_answer', (int)$user['id']);
    if ($text !== null && trim($text) !== '') {
        return ['reply' => trim($text), 'sources' => $sources, 'used_llm' => true];
    }
    $best = array_slice($hits, 0, 2);
    $reply = "Here is what CIT's rules say:\n\n" . implode("\n\n", array_map(fn($h, $i) => '[S' . ($i + 1) . '] ' . mb_substr($h['content'], 0, 600), $best, array_keys($best)));
    return ['reply' => $reply, 'sources' => array_slice($sources, 0, 2), 'used_llm' => false];
}
