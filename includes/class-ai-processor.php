<?php
class TTW_AI_Processor {
    public function rewrite_content(array $data): string|WP_Error {
        $html = $data['content'] ?? '';
        $link = trim($data['link'] ?? '');
        $extra_links = [];
        preg_match_all('/https?:\/\/[^\s<>"\')]+/i', (string)($data['full_text'] ?? ''), $m);
        foreach (($m[0] ?? []) as $u) {
            $u = rtrim($u, '.,;:)\'">');
            if ($u !== $link && !in_array($u, $extra_links, true)) $extra_links[] = $u;
        }
        if ($link) {
            $html .= '\n\n<p><strong>Fuente original (LinkedIn):</strong> <a href="' . esc_url($link) . '" target="_blank" rel="nofollow">Ver publicación</a></p>';
        }
        if (!empty($extra_links)) {
            $html .= "\n<p><strong>Lectura completa / enlaces adicionales:</strong></p>\n";
            foreach ($extra_links as $u) $html .= '<p><a href="' . esc_url($u) . '" target="_blank" rel="nofollow">' . esc_html($u) . '</a></p>';
        }
        return TTW_Auto_Linker::add_links($html);
    }
}
