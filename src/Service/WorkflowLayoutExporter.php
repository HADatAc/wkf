<?php

namespace Drupal\ctt\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Builds server-side workflow layout exports for Process URIs.
 */
class WorkflowLayoutExporter {

  /**
   * @var \Drupal\ctt\Service\CttHascoClient
   */
  protected $hascoClient;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(CttHascoClient $hascoClient, LoggerChannelFactoryInterface $loggerFactory) {
    $this->hascoClient = $hascoClient;
    $this->logger = $loggerFactory->get('ctt');
  }

  /**
   * Export process layout as SVG plus optional PNG data URI.
   */
  public function exportProcessLayout(string $processUri): array {
    $processUri = trim($processUri);
    if ($processUri === '') {
      return [
        'isSuccessful' => FALSE,
        'processUri' => '',
        'svgMarkup' => '',
        'pngDataUrl' => '',
        'nodeCount' => 0,
        'edgeCount' => 0,
        'width' => 0,
        'height' => 0,
        'message' => 'Missing process URI.',
      ];
    }

    try {
      $tasks = $this->hascoClient->getTasksByProcess($processUri);
      if (!is_array($tasks) || empty($tasks)) {
        return [
          'isSuccessful' => FALSE,
          'processUri' => $processUri,
          'svgMarkup' => '',
          'pngDataUrl' => '',
          'nodeCount' => 0,
          'edgeCount' => 0,
          'width' => 0,
          'height' => 0,
          'message' => 'No tasks returned for process.',
        ];
      }

      $graph = $this->buildGraph($tasks);
      if (empty($graph['nodes'])) {
        return [
          'isSuccessful' => FALSE,
          'processUri' => $processUri,
          'svgMarkup' => '',
          'pngDataUrl' => '',
          'nodeCount' => 0,
          'edgeCount' => 0,
          'width' => 0,
          'height' => 0,
          'message' => 'Task payload did not include node URIs.',
        ];
      }

      $layout = $this->layoutGraph($graph['nodes'], $graph['children'], $graph['temporalEdges']);
      $svgMarkup = $this->buildSvgMarkup($layout);
      $pngDataUrl = $this->buildPngDataUrl($layout);

      return [
        'isSuccessful' => TRUE,
        'processUri' => $processUri,
        'svgMarkup' => $svgMarkup,
        'pngDataUrl' => $pngDataUrl,
        'nodeCount' => count($layout['ordered']),
        'edgeCount' => $layout['edgeCount'],
        'width' => $layout['width'],
        'height' => $layout['height'],
        'message' => $pngDataUrl !== ''
          ? 'Workflow layout exported as SVG and PNG.'
          : 'Workflow layout exported as SVG. PNG fallback unavailable on this PHP runtime.',
      ];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Workflow layout export failed for @uri: @msg', [
        '@uri' => $processUri,
        '@msg' => $e->getMessage(),
      ]);

      return [
        'isSuccessful' => FALSE,
        'processUri' => $processUri,
        'svgMarkup' => '',
        'pngDataUrl' => '',
        'nodeCount' => 0,
        'edgeCount' => 0,
        'width' => 0,
        'height' => 0,
        'message' => $e->getMessage(),
      ];
    }
  }

  /**
   * Build normalized node and edge maps from task payloads.
   */
  protected function buildGraph(array $tasks): array {
    $nodes = [];
    $children = [];
    $parents = [];
    $temporalByTask = [];

    foreach ($tasks as $task) {
      if (!is_array($task)) {
        continue;
      }

      $uri = trim((string) ($task['uri'] ?? $task['hasURI'] ?? ''));
      if ($uri === '') {
        continue;
      }

      $label = trim((string) ($task['label'] ?? $task['name'] ?? $task['comment'] ?? $uri));
      if ($label === '') {
        $label = $uri;
      }

      $typeUri = trim((string) ($task['typeUri'] ?? $task['hascoTypeUri'] ?? ''));
      $temporalDependency = trim((string) ($task['temporalDependencyLabel'] ?? $task['hasTemporalDependency'] ?? ''));

      $nodes[$uri] = [
        'uri' => $uri,
        'label' => $label,
        'typeUri' => $typeUri,
        'temporalDependency' => $temporalDependency,
      ];
      $temporalByTask[$uri] = $temporalDependency;
      $children[$uri] = $children[$uri] ?? [];

      $subtaskUris = $task['hasSubtaskUris'] ?? $task['hasSubtask'] ?? [];
      if (is_string($subtaskUris)) {
        $subtaskUris = [$subtaskUris];
      }

      if (is_array($subtaskUris)) {
        foreach ($subtaskUris as $subtaskRef) {
          $childUri = '';
          if (is_string($subtaskRef)) {
            $childUri = trim($subtaskRef);
          }
          elseif (is_array($subtaskRef)) {
            $childUri = trim((string) ($subtaskRef['uri'] ?? $subtaskRef['hasURI'] ?? ''));
          }
          elseif (is_object($subtaskRef)) {
            $childUri = trim((string) ($subtaskRef->uri ?? $subtaskRef->hasURI ?? ''));
          }

          if ($childUri !== '') {
            $children[$uri][] = $childUri;
            $parents[$childUri] = $uri;
          }
        }
      }

      $superUri = trim((string) ($task['hasSupertaskUri'] ?? ''));
      if ($superUri !== '') {
        $parents[$uri] = $superUri;
        $children[$superUri] = $children[$superUri] ?? [];
        if (!in_array($uri, $children[$superUri], TRUE)) {
          $children[$superUri][] = $uri;
        }
      }
    }

    $temporalEdges = $this->buildTemporalEdges($nodes, $temporalByTask);

    return [
      'nodes' => $nodes,
      'children' => $children,
      'parents' => $parents,
      'temporalEdges' => $temporalEdges,
    ];
  }

  /**
   * Build temporal edges from vstoi:hasTemporalDependency expressions.
   */
  protected function buildTemporalEdges(array $nodes, array $temporalByTask): array {
    $edges = [];

    foreach ($temporalByTask as $taskUri => $dependency) {
      $parsed = $this->parseTemporalDependency($dependency);
      if ($parsed === NULL) {
        continue;
      }

      $operator = $parsed['operator'];
      $targets = $parsed['targets'];

      foreach ($targets as $targetUri) {
        if (!isset($nodes[$targetUri])) {
          continue;
        }

        // CTT semantics: "after X" means X -> current, "before X" means current -> X.
        if ($operator === 'after') {
          $source = $targetUri;
          $target = $taskUri;
        }
        elseif ($operator === 'before') {
          $source = $taskUri;
          $target = $targetUri;
        }
        else {
          // For choice/parallel/independent/disables/interrupts, link current -> referenced.
          $source = $taskUri;
          $target = $targetUri;
        }

        if (!isset($nodes[$source]) || !isset($nodes[$target]) || $source === '' || $target === '') {
          continue;
        }

        $edgeKey = $source . '|' . $target . '|' . $operator;
        $edges[$edgeKey] = [
          'source' => $source,
          'target' => $target,
          'operator' => $operator,
          'isBranching' => in_array($operator, ['choice', 'parallel', 'independent'], TRUE),
        ];
      }
    }

    return array_values($edges);
  }

  /**
   * Parse temporal dependency into operator + task URI targets.
   */
  protected function parseTemporalDependency(string $dependency): ?array {
    $dependency = trim((string) preg_replace('/\s+/', ' ', $dependency));
    if ($dependency === '') {
      return NULL;
    }

    if (!preg_match('/^([a-zA-Z]+)\s+(.+)$/', $dependency, $matches)) {
      return NULL;
    }

    $operator = strtolower(trim($matches[1]));
    $targetExpr = trim($matches[2]);
    if ($targetExpr === '') {
      return NULL;
    }

    $validOperators = ['after', 'before', 'parallel', 'choice', 'independent', 'disables', 'interrupts'];
    if (!in_array($operator, $validOperators, TRUE)) {
      return NULL;
    }

    $targets = [];
    $parts = preg_split('/[;,]/', $targetExpr) ?: [];
    foreach ($parts as $part) {
      $uri = trim((string) $part);
      if ($uri !== '') {
        $targets[] = $uri;
      }
    }

    if (empty($targets)) {
      return NULL;
    }

    return [
      'operator' => $operator,
      'targets' => array_values(array_unique($targets)),
    ];
  }

  /**
   * Compute deterministic visual layout for the task graph.
   */
  protected function layoutGraph(array $nodes, array $children, array $temporalEdges = []): array {
    $parents = [];
    foreach ($children as $parentUri => $childUris) {
      foreach ($childUris as $childUri) {
        $parents[$childUri] = $parentUri;
      }
    }

    $roots = [];
    foreach ($nodes as $uri => $_node) {
      if (!isset($parents[$uri]) || !isset($nodes[$parents[$uri]])) {
        $roots[] = $uri;
      }
    }

    if (empty($roots)) {
      $roots = [array_key_first($nodes)];
    }

    $ordered = [];
    $visited = [];
    $maxDepth = 0;

    $walk = function (string $uri, int $depth) use (&$walk, &$ordered, &$visited, &$maxDepth, $children, $nodes): void {
      if (isset($visited[$uri]) || !isset($nodes[$uri])) {
        return;
      }
      $visited[$uri] = TRUE;
      $maxDepth = max($maxDepth, $depth);
      $ordered[] = ['uri' => $uri, 'depth' => $depth];

      foreach (($children[$uri] ?? []) as $childUri) {
        $walk((string) $childUri, $depth + 1);
      }
    };

    foreach ($roots as $rootUri) {
      $walk((string) $rootUri, 0);
    }

    foreach (array_keys($nodes) as $uri) {
      if (!isset($visited[$uri])) {
        $walk((string) $uri, 0);
      }
    }

    $boxW = 340;
    $gapX = 54;
    $gapY = 48;
    $marginX = 30;
    $marginY = 28;

    $nodeRender = [];
    foreach ($nodes as $uri => $node) {
      $label = trim((string) ($node['label'] ?? ''));
      $titleLines = $this->wrapLabelLines($label, 40, 5);
      $depText = $this->normalizeDependencyText((string) ($node['temporalDependency'] ?? ''));
      $iconKind = $this->resolveNodeIconKind((string) ($node['typeUri'] ?? ''));

      $titleHeight = max(18, count($titleLines) * 18);
      $depHeight = $depText !== '' ? 16 : 0;
      $nodeHeight = max(88, 16 + 10 + $titleHeight + ($depHeight > 0 ? 8 + $depHeight : 0) + 12);

      $nodeRender[$uri] = [
        'titleLines' => $titleLines,
        'depText' => $depText,
        'iconKind' => $iconKind,
        'nodeHeight' => $nodeHeight,
      ];
    }

    $levels = [];
    foreach ($ordered as $entry) {
      $depth = (int) $entry['depth'];
      $levels[$depth] = $levels[$depth] ?? [];
      $levels[$depth][] = (string) $entry['uri'];
    }

    $maxPerLevel = 1;
    foreach ($levels as $levelUris) {
      $maxPerLevel = max($maxPerLevel, count($levelUris));
    }

    $width = $marginX * 2 + ($maxPerLevel * $boxW) + (max(0, $maxPerLevel - 1) * $gapX);
    $levelHeights = [];
    foreach ($levels as $depth => $levelUris) {
      $rowHeight = 88;
      foreach ($levelUris as $uri) {
        $rowHeight = max($rowHeight, (int) ($nodeRender[$uri]['nodeHeight'] ?? 88));
      }
      $levelHeights[$depth] = $rowHeight;
    }

    $height = $marginY * 2;
    for ($depth = 0; $depth <= $maxDepth; $depth++) {
      $height += (int) ($levelHeights[$depth] ?? 88);
      if ($depth < $maxDepth) {
        $height += $gapY;
      }
    }

    $positions = [];
    $cursorY = $marginY;
    foreach ($levels as $depth => $levelUris) {
      $rowCount = max(1, count($levelUris));
      $rowWidth = ($rowCount * $boxW) + (max(0, $rowCount - 1) * $gapX);
      $startX = (int) floor(($width - $rowWidth) / 2);
      $y = $cursorY;

      foreach ($levelUris as $idx => $uri) {
        $x = $startX + ($idx * ($boxW + $gapX));
        $positions[$uri] = ['x' => $x, 'y' => $y];
      }

      $cursorY += (int) ($levelHeights[$depth] ?? 88) + $gapY;
    }

    $edgeCount = 0;
    $hierarchyEdgeCount = 0;
    foreach ($children as $parentUri => $childUris) {
      if (!isset($positions[$parentUri])) {
        continue;
      }
      foreach ($childUris as $childUri) {
        if (isset($positions[$childUri])) {
          $hierarchyEdgeCount++;
          $edgeCount++;
        }
      }
    }

    $temporalEdgeCount = 0;
    foreach ($temporalEdges as $edge) {
      $source = (string) ($edge['source'] ?? '');
      $target = (string) ($edge['target'] ?? '');
      if ($source !== '' && $target !== '' && isset($positions[$source]) && isset($positions[$target])) {
        $temporalEdgeCount++;
        $edgeCount++;
      }
    }

    return [
      'nodes' => $nodes,
      'children' => $children,
      'temporalEdges' => $temporalEdges,
      'ordered' => $ordered,
      'positions' => $positions,
      'boxW' => $boxW,
      'nodeRender' => $nodeRender,
      'width' => $width,
      'height' => $height,
      'hierarchyEdgeCount' => $hierarchyEdgeCount,
      'temporalEdgeCount' => $temporalEdgeCount,
      'edgeCount' => $edgeCount,
    ];
  }

  /**
   * Render SVG markup for the computed layout.
   */
  protected function buildSvgMarkup(array $layout): string {
    $nodes = $layout['nodes'];
    $children = $layout['children'];
    $temporalEdges = $layout['temporalEdges'] ?? [];
    $ordered = $layout['ordered'];
    $positions = $layout['positions'];
    $nodeRender = $layout['nodeRender'];
    $boxW = (int) $layout['boxW'];
    $width = (int) $layout['width'];
    $height = (int) $layout['height'];

    $svg = [];
    $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">';
    $svg[] = '<defs>';
    $svg[] = '  <filter id="ctt-card-shadow" x="-10%" y="-20%" width="130%" height="160%">';
    $svg[] = '    <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#9ca3af" flood-opacity="0.10" />';
    $svg[] = '  </filter>';
    $svg[] = '  <marker id="ctt-arrow-default" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">';
    $svg[] = '    <path d="M 0 0 L 10 5 L 0 10 z" fill="#64748b" />';
    $svg[] = '  </marker>';
    $svg[] = '</defs>';
    $svg[] = '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="#ffffff" />';

    foreach ($children as $parentUri => $childUris) {
      if (!isset($positions[$parentUri])) {
        continue;
      }
      foreach ($childUris as $childUri) {
        if (!isset($positions[$childUri])) {
          continue;
        }

        $from = $positions[$parentUri];
        $to = $positions[$childUri];
        $fromH = (int) ($nodeRender[$parentUri]['nodeHeight'] ?? 88);
        $x1 = (int) $from['x'] + (int) floor($boxW / 2);
        $y1 = (int) $from['y'] + $fromH;
        $x2 = (int) $to['x'] + (int) floor($boxW / 2);
        $y2 = (int) $to['y'];
        $midY = (int) floor(($y1 + $y2) / 2);

        $svg[] = '<path d="M ' . $x1 . ' ' . $y1 . ' C ' . $x1 . ' ' . $midY . ', ' . $x2 . ' ' . $midY . ', ' . $x2 . ' ' . $y2 . '" stroke="#b4bfcb" stroke-width="1.6" fill="none" stroke-linecap="round" />';
      }
    }

    // Temporal dependency edges with operator-specific visual semantics.
    foreach ($temporalEdges as $edge) {
      $sourceUri = (string) ($edge['source'] ?? '');
      $targetUri = (string) ($edge['target'] ?? '');
      $operator = strtolower((string) ($edge['operator'] ?? ''));
      if (!isset($positions[$sourceUri]) || !isset($positions[$targetUri])) {
        continue;
      }

      $style = $this->temporalEdgeStyle($operator);
      $sourcePos = $positions[$sourceUri];
      $targetPos = $positions[$targetUri];
      $sourceH = (int) ($nodeRender[$sourceUri]['nodeHeight'] ?? 88);
      $targetH = (int) ($nodeRender[$targetUri]['nodeHeight'] ?? 88);

      $x1 = (int) $sourcePos['x'] + (int) floor($boxW / 2);
      $y1 = (int) $sourcePos['y'] + (int) floor($sourceH / 2);
      $x2 = (int) $targetPos['x'] + (int) floor($boxW / 2);
      $y2 = (int) $targetPos['y'] + (int) floor($targetH / 2);

      $controlX = (int) floor(($x1 + $x2) / 2);
      $controlY = (int) floor(($y1 + $y2) / 2) - 18;

      $path = 'M ' . $x1 . ' ' . $y1 . ' Q ' . $controlX . ' ' . $controlY . ', ' . $x2 . ' ' . $y2;
      $dash = $style['dash'] !== '' ? ' stroke-dasharray="' . $style['dash'] . '"' : '';
      $svg[] = '<path d="' . $path . '" stroke="' . $style['stroke'] . '" stroke-width="' . $style['width'] . '" fill="none" stroke-linecap="round" marker-end="url(#ctt-arrow-default)"' . $dash . ' opacity="0.92" />';

      // Branch junction marker at edge source for branching operators.
      if (!empty($edge['isBranching'])) {
        $markerX = $x1;
        $markerY = $y1;
        $svg[] = '<circle cx="' . $markerX . '" cy="' . $markerY . '" r="6" fill="#ffffff" stroke="' . $style['stroke'] . '" stroke-width="1.8" />';
        $svg[] = '<text x="' . $markerX . '" y="' . ($markerY + 3) . '" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="8" font-weight="700" fill="' . $style['stroke'] . '">' . htmlspecialchars($style['short'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8') . '</text>';
      }
    }

    foreach ($ordered as $entry) {
      $uri = $entry['uri'];
      $node = $nodes[$uri] ?? ['label' => $uri];
      $pos = $positions[$uri];
      $render = $nodeRender[$uri] ?? [
        'titleLines' => [$node['label'] ?? $uri],
        'depText' => '',
        'iconKind' => 'generic',
        'nodeHeight' => 88,
      ];
      $wrapped = is_array($render['titleLines']) ? $render['titleLines'] : [($node['label'] ?? $uri)];
      $depText = (string) ($render['depText'] ?? '');
      $nodeHeight = (int) ($render['nodeHeight'] ?? 88);
      $iconKind = (string) ($render['iconKind'] ?? 'generic');
      $x = (int) $pos['x'];
      $y = (int) $pos['y'];
      $clipId = 'ctt-card-clip-' . substr(sha1($uri), 0, 10);

      $svg[] = '<rect x="' . $x . '" y="' . $y . '" rx="12" ry="12" width="' . $boxW . '" height="' . $nodeHeight . '" fill="#ffffff" stroke="#d9e1ea" stroke-width="1" filter="url(#ctt-card-shadow)" />';
      $svg[] = '<defs><clipPath id="' . $clipId . '"><rect x="' . ($x + 8) . '" y="' . ($y + 6) . '" width="' . ($boxW - 16) . '" height="' . ($nodeHeight - 12) . '" /></clipPath></defs>';

      $headerY = $y + 16;
      $iconCx = $x + (int) floor($boxW / 2) - 18;
      if ($iconKind === 'person') {
        $svg[] = '<circle cx="' . $iconCx . '" cy="' . ($headerY - 3) . '" r="4" fill="#4b5563" />';
        $svg[] = '<path d="M ' . ($iconCx - 6) . ' ' . ($headerY + 8) . ' C ' . ($iconCx - 3) . ' ' . ($headerY + 2) . ', ' . ($iconCx + 3) . ' ' . ($headerY + 2) . ', ' . ($iconCx + 6) . ' ' . ($headerY + 8) . ' Z" fill="#4b5563" />';
      }
      elseif ($iconKind === 'device') {
        $svg[] = '<rect x="' . ($iconCx - 7) . '" y="' . ($headerY - 8) . '" width="14" height="10" rx="1.5" ry="1.5" fill="#4b5563" />';
        $svg[] = '<rect x="' . ($iconCx - 3) . '" y="' . ($headerY + 2) . '" width="6" height="2" fill="#4b5563" />';
      }
      else {
        $svg[] = '<circle cx="' . $iconCx . '" cy="' . ($headerY - 2) . '" r="5" fill="#4b5563" />';
      }
      $svg[] = '<text x="' . ($iconCx + 14) . '" y="' . ($headerY + 2) . '" text-anchor="start" font-family="DejaVu Sans, sans-serif" font-size="15" font-weight="700" fill="#2563eb">&gt;&gt;</text>';

      // Dompdf frequently collapses tspan-based line breaks; draw each line explicitly.
      $textYStart = $y + 38;
      $lineHeight = 17;
      $textX = $x + (int) floor($boxW / 2);
      $svg[] = '<g clip-path="url(#' . $clipId . ')">';
      foreach ($wrapped as $lineIndex => $line) {
        $safeLine = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $lineY = $textYStart + ($lineIndex * $lineHeight);
        $svg[] = '<text x="' . $textX . '" y="' . $lineY . '" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="13" fill="#374151">' . $safeLine . '</text>';
      }

      if ($depText !== '') {
        $safeDep = htmlspecialchars($depText, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
        $svg[] = '<text x="' . ($x + (int) floor($boxW / 2)) . '" y="' . ($y + $nodeHeight - 10) . '" text-anchor="middle" font-family="DejaVu Sans, sans-serif" font-size="11" fill="#6b7280">' . $safeDep . '</text>';
      }
      $svg[] = '</g>';
    }

    $svg[] = '</svg>';

    return implode('', $svg);
  }

  /**
   * Normalize dependency text so it is short and readable in cards.
   */
  protected function normalizeDependencyText(string $dependency): string {
    $dependency = trim(preg_replace('/\s+/', ' ', $dependency) ?? '');
    if ($dependency === '') {
      return '';
    }

    $dependency = preg_replace('#https?://[^\s]+#', 'uri', $dependency) ?? $dependency;
    if (strlen($dependency) > 52) {
      $dependency = substr($dependency, 0, 49) . '...';
    }

    return $dependency;
  }

  /**
   * Resolve simplified icon kind from task type URI.
   */
  protected function resolveNodeIconKind(string $typeUri): string {
    $value = strtolower(trim($typeUri));
    if ($value === '') {
      return 'generic';
    }

    if (str_contains($value, 'interaction')) {
      return 'person';
    }

    if (str_contains($value, 'system') || str_contains($value, 'device') || str_contains($value, 'machine')) {
      return 'device';
    }

    return 'generic';
  }

  /**
   * Wrap label text into a limited number of lines.
   */
  protected function wrapLabelLines(string $label, int $maxCharsPerLine = 44, int $maxLines = 2): array {
    $clean = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if ($clean === '') {
      return [''];
    }

    $words = preg_split('/\s+/', $clean) ?: [$clean];
    $normalizedWords = [];
    foreach ($words as $word) {
      $word = (string) $word;
      if (strlen($word) <= $maxCharsPerLine) {
        $normalizedWords[] = $word;
        continue;
      }

      // Split very long tokens so they can wrap within the card width.
      $chunks = str_split($word, max(1, $maxCharsPerLine - 1));
      foreach ($chunks as $chunkIndex => $chunk) {
        if ($chunkIndex < count($chunks) - 1) {
          $normalizedWords[] = $chunk . '-';
        }
        else {
          $normalizedWords[] = $chunk;
        }
      }
    }

    $lines = [];
    $current = '';
    $truncated = FALSE;

    foreach ($normalizedWords as $word) {
      $candidate = $current === '' ? $word : ($current . ' ' . $word);
      if (strlen($candidate) <= $maxCharsPerLine) {
        $current = $candidate;
        continue;
      }

      if ($current !== '') {
        $lines[] = $current;
      }
      $current = $word;

      if (count($lines) >= $maxLines) {
        $truncated = TRUE;
        break;
      }
    }

    if ($current !== '' && count($lines) < $maxLines) {
      $lines[] = $current;
    }
    elseif ($current !== '') {
      $truncated = TRUE;
    }

    if (count($lines) > $maxLines) {
      $lines = array_slice($lines, 0, $maxLines);
      $truncated = TRUE;
    }

    if (!empty($lines)) {
      $lastIndex = count($lines) - 1;
      if ($truncated || strlen($lines[$lastIndex]) > $maxCharsPerLine) {
        $safeMax = max(1, $maxCharsPerLine - 3);
        $lines[$lastIndex] = rtrim(substr($lines[$lastIndex], 0, $safeMax), '- ') . '...';
      }
    }

    return $lines;
  }

  /**
   * Render a simplified PNG data URI if GD is available.
   */
  protected function buildPngDataUrl(array $layout): string {
    if (!function_exists('imagecreatetruecolor')) {
      return '';
    }

    $nodes = $layout['nodes'];
    $children = $layout['children'];
    $temporalEdges = $layout['temporalEdges'] ?? [];
    $ordered = $layout['ordered'];
    $positions = $layout['positions'];
    $nodeRender = $layout['nodeRender'] ?? [];
    $boxW = (int) $layout['boxW'];
    $width = max(1, (int) $layout['width']);
    $height = max(1, (int) $layout['height']);

    if ($width > 2600 || $height > 3400) {
      return '';
    }

    $img = @imagecreatetruecolor($width, $height);
    if (!$img) {
      return '';
    }

    $white = imagecolorallocate($img, 255, 255, 255);
    $edge = imagecolorallocate($img, 138, 161, 184);
    $border = imagecolorallocate($img, 47, 110, 169);
    $fill = imagecolorallocate($img, 247, 251, 255);
    $text = imagecolorallocate($img, 15, 46, 77);

    imagefilledrectangle($img, 0, 0, $width, $height, $white);

    foreach ($children as $parentUri => $childUris) {
      if (!isset($positions[$parentUri])) {
        continue;
      }
      foreach ($childUris as $childUri) {
        if (!isset($positions[$childUri])) {
          continue;
        }

        $from = $positions[$parentUri];
        $to = $positions[$childUri];
        $fromH = (int) (($nodeRender[$parentUri]['nodeHeight'] ?? 88));
        $x1 = (int) $from['x'] + (int) floor($boxW / 2);
        $y1 = (int) $from['y'] + $fromH;
        $x2 = (int) $to['x'] + (int) floor($boxW / 2);
        $y2 = (int) $to['y'];
        $midY = (int) floor(($y1 + $y2) / 2);

        imageline($img, $x1, $y1, $x1, $midY, $edge);
        imageline($img, $x1, $midY, $x2, $midY, $edge);
        imageline($img, $x2, $midY, $x2, $y2, $edge);
      }
    }

    // Draw temporal edges on top of hierarchy edges.
    foreach ($temporalEdges as $temporalEdge) {
      $sourceUri = (string) ($temporalEdge['source'] ?? '');
      $targetUri = (string) ($temporalEdge['target'] ?? '');
      $operator = strtolower((string) ($temporalEdge['operator'] ?? ''));

      if (!isset($positions[$sourceUri]) || !isset($positions[$targetUri])) {
        continue;
      }

      $style = $this->temporalEdgeStyle($operator);
      $colorHex = ltrim((string) $style['stroke'], '#');
      if (strlen($colorHex) !== 6) {
        $colorHex = '64748b';
      }
      $r = hexdec(substr($colorHex, 0, 2));
      $g = hexdec(substr($colorHex, 2, 2));
      $b = hexdec(substr($colorHex, 4, 2));
      $lineColor = imagecolorallocate($img, $r, $g, $b);

      $sourcePos = $positions[$sourceUri];
      $targetPos = $positions[$targetUri];
      $sourceH = (int) (($nodeRender[$sourceUri]['nodeHeight'] ?? 88));
      $targetH = (int) (($nodeRender[$targetUri]['nodeHeight'] ?? 88));

      $x1 = (int) $sourcePos['x'] + (int) floor($boxW / 2);
      $y1 = (int) $sourcePos['y'] + (int) floor($sourceH / 2);
      $x2 = (int) $targetPos['x'] + (int) floor($boxW / 2);
      $y2 = (int) $targetPos['y'] + (int) floor($targetH / 2);

      imageline($img, $x1, $y1, $x2, $y2, $lineColor);

      // Branch marker for choice/parallel/independent.
      if (!empty($temporalEdge['isBranching'])) {
        imagefilledellipse($img, $x1, $y1, 10, 10, $white);
        imageellipse($img, $x1, $y1, 10, 10, $lineColor);
        imagestring($img, 1, $x1 - 3, $y1 - 4, (string) ($style['short'] ?? ''), $lineColor);
      }
    }

    foreach ($ordered as $entry) {
      $uri = $entry['uri'];
      $node = $nodes[$uri] ?? ['label' => $uri];
      $pos = $positions[$uri];
      $render = $nodeRender[$uri] ?? [];
      $nodeHeight = (int) ($render['nodeHeight'] ?? 88);

      $x = (int) $pos['x'];
      $y = (int) $pos['y'];
      imagefilledrectangle($img, $x, $y, $x + $boxW, $y + $nodeHeight, $fill);
      imagerectangle($img, $x, $y, $x + $boxW, $y + $nodeHeight, $border);

      $label = preg_replace('/\s+/', ' ', (string) ($node['label'] ?? $uri));
      $label = is_string($label) ? trim($label) : '';
      if ($label === '') {
        $label = $uri;
      }
      if (strlen($label) > 60) {
        $label = substr($label, 0, 57) . '...';
      }

      imagestring($img, 2, $x + 8, $y + 20, $label, $text);
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);

    if (!is_string($png) || $png === '') {
      return '';
    }

    return 'data:image/png;base64,' . base64_encode($png);
  }

  /**
   * Style map for temporal operators.
   */
  protected function temporalEdgeStyle(string $operator): array {
    switch (strtolower(trim($operator))) {
      case 'choice':
        return [
          'stroke' => '#dc2626',
          'dash' => '6 4',
          'width' => '2',
          'short' => 'C',
        ];

      case 'parallel':
        return [
          'stroke' => '#0891b2',
          'dash' => '3 3',
          'width' => '2',
          'short' => 'P',
        ];

      case 'independent':
        return [
          'stroke' => '#7c3aed',
          'dash' => '1 4',
          'width' => '2',
          'short' => 'I',
        ];

      case 'after':
      case 'before':
        return [
          'stroke' => '#334155',
          'dash' => '',
          'width' => '1.7',
          'short' => 'S',
        ];

      case 'disables':
        return [
          'stroke' => '#ea580c',
          'dash' => '8 4',
          'width' => '2',
          'short' => 'D',
        ];

      case 'interrupts':
        return [
          'stroke' => '#be123c',
          'dash' => '8 3 2 3',
          'width' => '2',
          'short' => 'X',
        ];

      default:
        return [
          'stroke' => '#64748b',
          'dash' => '4 3',
          'width' => '1.8',
          'short' => 'T',
        ];
    }
  }
}
