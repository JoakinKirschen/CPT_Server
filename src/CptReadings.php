<?php

declare(strict_types=1);

namespace CptLibrary;

/**
 * The quality check of an uploaded CPT (1.2.0): reads the depth, qc and fs
 * columns of its GEF or CSV text and lists what makes it unusable
 * (problems: the upload is refused) or doubtful (warnings: stored, and the
 * uploader is told). It reads; it never changes the stored text.
 *
 *   GEF  the columns named by #COLUMNINFO quantity numbers: 11 corrected
 *        depth or else 1 penetration length (m), 2 qc, 3 fs (MPa, or kPa
 *        converted), with #COLUMNSEPARATOR, #RECORDSEPARATOR and
 *        #COLUMNVOID; without #COLUMNINFO, depth, qc and fs in that order.
 *   CSV  depth (m), qc (MPa) and optionally fs (MPa), in that order or by a
 *        header row naming them; separated by ; , or tab, with a decimal
 *        comma when the separator is ;.
 */
final class CptReadings
{
    public const MIN_READINGS = 10;
    private const MAX_DEPTH_M = 100.0;
    private const MAX_QC_MPA = 100.0;
    private const MAX_FS_MPA = 2.0;

    /** @param list<array{0: float, 1: float, 2: ?float}> $rows depth, qc, fs */
    private function __construct(
        public readonly array $rows,
        /** @var list<string> */
        public readonly array $problems,
        /** @var list<string> */
        public readonly array $warnings,
    ) {
    }

    public static function read(string $format, string $content): self
    {
        [$rows, $problems] = $format === 'gef' ? self::gefRows($content) : self::csvRows($content);
        return self::check($rows, $problems);
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function depthMin(): ?float
    {
        return $this->rows === [] ? null : min(array_column($this->rows, 0));
    }

    public function depthMax(): ?float
    {
        return $this->rows === [] ? null : max(array_column($this->rows, 0));
    }

    /** @return array{0: list<array{0: float, 1: float, 2: ?float}>, 1: list<string>} */
    private static function gefRows(string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $columns = [];
        $voids = [];
        $separator = null;
        $recordSeparator = null;
        $dataStart = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^#EOH\s*=/i', $line) === 1) {
                $dataStart = $index + 1;
                break;
            }
            if (preg_match('/^#COLUMNINFO\s*=\s*(\d+)\s*,\s*([^,]*),\s*([^,]*),\s*(\d+)/i', $line, $match) === 1) {
                $columns[(int)$match[4]] = ['index' => (int)$match[1] - 1, 'unit' => strtolower(trim($match[2]))];
            } elseif (preg_match('/^#COLUMNVOID\s*=\s*(\d+)\s*,\s*([-+0-9.eE]+)/i', $line, $match) === 1) {
                $voids[(int)$match[1] - 1] = (float)$match[2];
            } elseif (preg_match('/^#COLUMNSEPARATOR\s*=\s*(.)/i', $line, $match) === 1) {
                $separator = $match[1];
            } elseif (preg_match('/^#RECORDSEPARATOR\s*=\s*(.)/i', $line, $match) === 1) {
                $recordSeparator = $match[1];
            }
        }
        if ($dataStart === null) {
            return [[], ['The GEF file has no #EOH line.']];
        }
        $depthColumn = $columns[11] ?? $columns[1] ?? ['index' => 0, 'unit' => 'm'];
        $qcColumn = $columns[2] ?? ($columns === [] ? ['index' => 1, 'unit' => 'mpa'] : null);
        $fsColumn = $columns[3] ?? ($columns === [] ? ['index' => 2, 'unit' => 'mpa'] : null);
        if ($qcColumn === null) {
            return [[], ['The GEF file has no cone resistance column (#COLUMNINFO quantity 2).']];
        }
        $rows = [];
        foreach (array_slice($lines, $dataStart) as $line) {
            $line = trim($line);
            if ($recordSeparator !== null) {
                $line = rtrim(trim($line), $recordSeparator . " \t");
            }
            if ($line === '') {
                continue;
            }
            $cells = $separator !== null && trim($separator) !== '' ? explode($separator, $line) : (preg_split('/\s+/', $line) ?: []);
            $cell = static function (?array $column) use ($cells, $voids): ?float {
                if ($column === null || !isset($cells[$column['index']])) {
                    return null;
                }
                $text = trim($cells[$column['index']]);
                if (!is_numeric($text)) {
                    return null;
                }
                $value = (float)$text;
                if (isset($voids[$column['index']]) && abs($value - $voids[$column['index']]) < 1e-9) {
                    return null;
                }
                return str_contains($column['unit'], 'kpa') ? $value / 1000 : $value;
            };
            $depth = $cell($depthColumn);
            $qc = $cell($qcColumn);
            if ($depth === null || $qc === null) {
                continue;
            }
            $rows[] = [$depth, $qc, $cell($fsColumn)];
        }
        return [$rows, []];
    }

    /** @return array{0: list<array{0: float, 1: float, 2: ?float}>, 1: list<string>} */
    private static function csvRows(string $content): array
    {
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $content) ?: [], static fn(string $line): bool => trim($line) !== ''));
        if ($lines === []) {
            return [[], ['The CSV text is empty.']];
        }
        $separator = str_contains($lines[0], ';') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');
        $number = static function (string $text) use ($separator): ?float {
            $text = trim($text, " \t\"'");
            if ($separator === ';') {
                $text = str_replace(',', '.', $text);
            }
            return is_numeric($text) ? (float)$text : null;
        };
        $order = [0, 1, 2];
        $header = explode($separator, $lines[0]);
        if (count(array_filter($header, static fn(string $cell): bool => $number($cell) === null)) >= 2) {
            $find = static function (string $pattern) use ($header): ?int {
                foreach ($header as $index => $name) {
                    if (preg_match($pattern, strtolower(trim($name, " \t\"'"))) === 1) {
                        return $index;
                    }
                }
                return null;
            };
            $order = [
                $find('/^(depth|diepte|sondeerdiepte|lengte|penetration|z)\b/') ?? 0,
                $find('/^(qc|conusweerstand|cone)/') ?? 1,
                $find('/^(fs|wrijving|sleeve|plaatselijke)/'),
            ];
            array_shift($lines);
        }
        $rows = [];
        foreach ($lines as $line) {
            $cells = explode($separator, $line);
            $depth = isset($cells[$order[0]]) ? $number($cells[$order[0]]) : null;
            $qc = isset($cells[$order[1]]) ? $number($cells[$order[1]]) : null;
            if ($depth === null || $qc === null) {
                continue;
            }
            $fs = $order[2] !== null && isset($cells[$order[2]]) ? $number($cells[$order[2]]) : null;
            $rows[] = [$depth, $qc, $fs];
        }
        return [$rows, []];
    }

    /** @param list<array{0: float, 1: float, 2: ?float}> $rows */
    private static function check(array $rows, array $problems): self
    {
        $warnings = [];
        if ($problems !== []) {
            return new self($rows, $problems, $warnings);
        }
        if (count($rows) < self::MIN_READINGS) {
            $problems[] = 'At least ' . self::MIN_READINGS . ' readings with a depth and qc are needed; found ' . count($rows) . '.';
            return new self($rows, $problems, $warnings);
        }
        $badDepth = $badQc = $badFs = $highRf = 0;
        $firstDecrease = null;
        foreach ($rows as $index => [$depth, $qc, $fs]) {
            if ($depth < 0 || $depth > self::MAX_DEPTH_M) {
                $badDepth++;
            }
            if ($qc < 0 || $qc > self::MAX_QC_MPA) {
                $badQc++;
            }
            if ($fs !== null && ($fs < 0 || $fs > self::MAX_FS_MPA)) {
                $badFs++;
            }
            if ($fs !== null && $qc > 0.05 && $fs / $qc * 100 > 15) {
                $highRf++;
            }
            if ($index > 0 && $firstDecrease === null && $depth < $rows[$index - 1][0] - 0.01) {
                $firstDecrease = $index;
            }
        }
        if ($firstDecrease !== null) {
            $problems[] = sprintf('The depths must increase: reading %d goes from %.2f m back to %.2f m.', $firstDecrease + 1, $rows[$firstDecrease - 1][0], $rows[$firstDecrease][0]);
        }
        if ($badDepth > 0) {
            $problems[] = "{$badDepth} depth(s) are outside 0-" . (int)self::MAX_DEPTH_M . ' m.';
        }
        if ($badQc > 0) {
            $problems[] = "{$badQc} qc value(s) are outside 0-" . (int)self::MAX_QC_MPA . ' MPa (were they in kPa?).';
        }
        if ($badFs > 0) {
            $problems[] = "{$badFs} fs value(s) are outside 0-" . self::MAX_FS_MPA . ' MPa (were they in kPa?).';
        }
        $depths = array_column($rows, 0);
        if (max($depths) - min($depths) < 0.5) {
            $problems[] = 'The readings cover less than 0.5 m of depth.';
        }
        if (max(array_column($rows, 1)) <= 0) {
            $problems[] = 'Every qc value is zero.';
        }
        if (count(array_filter(array_column($rows, 2), static fn(?float $fs): bool => $fs !== null)) === 0) {
            $warnings[] = 'No sleeve friction (fs) was found.';
        }
        if ($highRf > count($rows) * 0.05) {
            $warnings[] = "{$highRf} reading(s) have a friction ratio above 15 %: check the fs unit.";
        }
        return new self($rows, $problems, $warnings);
    }
}
