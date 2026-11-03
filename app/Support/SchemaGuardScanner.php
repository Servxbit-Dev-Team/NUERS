<?php

namespace App\Support;

/**
 * Finds every "does this table/column exist?" check in the application source (Schema::hasTable / hasColumn /
 * hasColumns, and the SchemaMap wrappers). Each check marks a place where code was written to survive a database
 * that is behind the migrations. Used by `schema:drift` to show which of those checks are firing on a real database.
 */
class SchemaGuardScanner
{
    /** @return array<int,array{file:string,line:int,kind:string,table:?string,columns:array<int,string>,dynamic:bool}> */
    public function scan(?array $roots = null): array
    {
        $roots ??= [base_path('app'), base_path('routes')];
        $found = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_ends_with($path, 'app/Support/SchemaGuardScanner.php') || str_ends_with($path, 'app/Support/SchemaMap.php')) {
                    continue;
                }
                foreach ($this->scanFile($path) as $hit) {
                    $found[] = $hit;
                }
            }
        }
        usort($found, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $found;
    }

    /** @return array<int,array{file:string,line:int,kind:string,table:?string,columns:array<int,string>,dynamic:bool}> */
    public function scanFile(string $path): array
    {
        $hits = [];
        $relative = ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/');
        foreach (file($path) as $index => $text) {
            if (! preg_match_all('/(?:Schema|SchemaMap)::(hasTable|hasColumns?)\(/', $text, $calls, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($calls[1] as $i => [$kind, $offset]) {
                $argsText = $this->arguments($text, $offset + strlen($kind) + 1);
                $args = $this->splitArguments($argsText);
                $table = $this->literal($args[0] ?? '');
                $columns = [];
                $dynamic = $table === null;
                if ($kind !== 'hasTable') {
                    $second = trim($args[1] ?? '');
                    if (str_starts_with($second, '[')) {
                        preg_match_all('/([\'"])([^\'"]+)\1/', $second, $names);
                        $columns = $names[2];
                    } elseif (($name = $this->literal($second)) !== null) {
                        $columns = [$name];
                    } else {
                        $dynamic = true;
                    }
                }
                $hits[] = ['file' => $relative, 'line' => $index + 1, 'kind' => $kind, 'table' => $table, 'columns' => $columns, 'dynamic' => $dynamic];
            }
        }

        return $hits;
    }

    /** Text between the opening parenthesis (already consumed) and its matching close, on the same line. */
    private function arguments(string $line, int $from): string
    {
        $depth = 1;
        $quote = null;
        for ($i = $from, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($quote) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '\'' || $c === '"') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                if (--$depth === 0) {
                    return substr($line, $from, $i - $from);
                }
            }
        }

        return substr($line, $from);
    }

    /** @return array<int,string> */
    private function splitArguments(string $text): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $c = $text[$i];
            if ($quote) {
                $current .= $c;
                if ($c === '\\' && $i + 1 < $n) {
                    $current .= $text[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($c === '\'' || $c === '"') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $c;
        }
        $parts[] = trim($current);

        return $parts;
    }

    private function literal(string $argument): ?string
    {
        return preg_match('/^([\'"])([A-Za-z0-9_]+)\1$/', trim($argument), $m) ? $m[2] : null;
    }
}
