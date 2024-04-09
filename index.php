<?php

/*
 * The MIT License
 *
 * Copyright 2024 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');

use acdhOeaw\arche\lib\SearchTerm;
use termTemplates\PredicateTemplate as PT;
use termTemplates\QuadTemplate as QT;

include __DIR__ . '/vendor/autoload.php';

$cfgPath = getenv('CFG_PATH') ?: '';
$dbRole = getenv('DB_ROLE') ?: 'guest';
$allowedNmsp = getenv('ALLOWED_NMSP') ?: '';
$allowedNmsp = empty($allowedNmsp) ? [] : explode(',', $allowedNmsp);
$lorisBaseUrl = getenv('LORIS_BASE') ?: '';
$defaultMode = getenv('DEFAULT_MODE') ?: 'image';

$id           = $_GET['id'] ?? $argv[1] ?? '';
$mode         = $_GET['mode'] ?? $argv[2] ?? $defaultMode;

if (!empty($cfgPath)) {
    $repo = acdhOeaw\arche\lib\RepoDb::factory($cfgPath, $dbRole);
} else {
    $match = false;
    foreach ($allowedNmsp as $i) {
        if (str_starts_with($id, $i)) {
            $match = true;
            break;
        }
    }
    if (!$match) {
        http_response_code(400);
        echo "Resource $id out of allowed namespaces\n";
        exit();
    }
    $repo = acdhOeaw\arche\lib\Repo::factoryFromUrl($id);
}
$schema    = $repo->getSchema();
$mimeTmpl  = new PT($schema->mime);
$labelTmpl = new PT($schema->label);
$idTmpl    = new PT($schema->id);
$nextTmpl  = new PT($schema->nextItem);
$idNmsp    = (string) $schema->namespaces->id;

$cfg  = new acdhOeaw\arche\lib\SearchConfig();
$cfg->metadataMode = $mode === 'image' ? '0_0_0_0' : '99999_99999_0_0';
$cfg->metadataParentProperty = $schema->nextItem;
$cfg->resourceProperties = [
    (string) $schema->nextItem,
    (string) $schema->id,
    (string) $schema->mime,
];
if ($mode === 'manifest') {
    $cfg->resourceProperties[] = (string) $schema->label;
}
$cfg->relativesProperties = $cfg->resourceProperties;
$term = new SearchTerm(value: substr($id, strlen($repo->getBaseUrl())), type: SearchTerm::TYPE_ID);
$graph = $repo->getGraphBySearchTerms([$term], $cfg);

$sbjMap = [];
foreach ($graph->getIterator(new PT($schema->id)) as $triple) {
    if (str_starts_with((string) $triple->getObject(), $idNmsp)) {
        $sbjMap[(string) $triple->getSubject()] = (string) $triple->getObject();
    }
}
$getImgInfoUrl = fn($id) => $lorisBaseUrl . preg_replace('`https?://[^/]+/`', '', $sbjMap[$id]) . "/info.json";

$data = null;
if ($mode === 'image') {
    http_response_code(302);
    header("Location: " . $getImgInfoUrl($id));
    exit();
} elseif (!in_array($mode, ['images', 'manifest'])) {
    http_response_code(400);
    echo "Unknown mode $mode\n";
    exit();
}
$first = null;
$repoBaseUrl = $repo->getBaseUrl();
foreach ($graph->listSubjects() as $sbj) {
    if ($repoBaseUrl !== (string) $sbj && $graph->none($nextTmpl->withObject($sbj))) {
        $first = $sbj;
        break;
    }
}
if ($first === null) {
    http_response_code(500);
    exit("Couldn't find first resource in the sequence");
}
$sbj = $first;
if ($mode === 'images') {
    $data = ['index' => null, 'images' => []];
    while ($sbj) {
        $tmp = $graph->copy(new QT($sbj));
        if (str_starts_with((string) $tmp->getObjectValue($mimeTmpl), 'image/')) {
            if ($id === (string) $sbj) {
                $data['index'] = count($data);
            } 
            $data['images'][] = $getImgInfoUrl((string) $sbj);
        }
        $sbj = $tmp->getObject($nextTmpl);
    }
} else {
    $formatTitles = fn($x) => ['@value' => $x->getValue(), '@language' => $x->getLang()];
    $titles = iterator_to_array($graph->listObjects($labelTmpl->withSubject($first)));

    $canvases = [];
    while ($sbj) {
        $tmp = $graph->copy(new QT($sbj));
        if (str_starts_with((string) $tmp->getObjectValue($mimeTmpl), 'image/')) {
            $infoUrl    = $getImgInfoUrl((string) $sbj);
            $meta       = json_decode(file_get_contents($infoUrl));
            $canvases[] = [
                '@id'    => $sbj . '#IIIF-canvas',
                'label'  => array_map($formatTitles, iterator_to_array($tmp->listObjects($labelTmpl))),
                'height' => $meta->height,
                'width'  => $meta->width,
                'images' => [
                    [
                        '@type'      => 'oa:Annotation',
                        'motivation' => 'sc:painting',
                        'on'         => $sbj . '#IIIF-canvas',
                        'resource'   => [
                            '@id'     => $infoUrl,
                            '@type'   => 'dctypes:Image',
                            'service' => [
                                'profile' => $meta->profile[0],
                            ],
                            'height' => $meta->height,
                            'width'  => $meta->width,
                        ],
                    ],
                ],
            ];
        }
        $sbj = $tmp->getObject($nextTmpl);
    }

    $data = [
        '@context'  => 'http://iiif.io/api/presentation/2/context.json',
        '@id'       => $first . '#IIIF-manifest', # maybe its PID?
        '@type'     => 'sc:Manifest',
        'label'     => array_map($formatTitles, $titles),
        'metadata'  => [],
        'sequences' => [
            [
                '@id'      => $first . '#IIIF-Sequence',
                '@type'    => 'sc:Sequence',
                'canvases' => $canvases,
            ],
        ],
    ];
}

header('Content-Type: application/json');
echo json_encode($data, JSON_UNESCAPED_SLASHES);
