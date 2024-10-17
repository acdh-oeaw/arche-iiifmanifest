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
use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use termTemplates\QuadTemplate as QT;
use zozlak\RdfConstants as RDF;

include __DIR__ . '/vendor/autoload.php';

$cfgPath      = getenv('CFG_PATH') ?: '';
$dbRole       = getenv('DB_ROLE') ?: 'guest';
$allowedNmsp  = getenv('ALLOWED_NMSP') ?: '';
$allowedNmsp  = empty($allowedNmsp) ? [] : explode(',', $allowedNmsp);
$lorisBaseUrl = getenv('LORIS_BASE') ?: '';
$defaultMode  = getenv('DEFAULT_MODE') ?: 'image';
$baseUrl      = getenv('BASE_URL') ?: '';
$profile      = getenv('PROFILE') ?? null;
$getDimenions = getenv('GET_DIMENSIONS') ?? false;

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
$schema     = $repo->getSchema();
$mimeTmpl   = new PT($schema->mime);
$labelTmpl  = new PT($schema->label);
$idTmpl     = new PT($schema->id);
$nextTmpl   = new PT($schema->nextItem);
$widthTmpl  = new PT($schema->imagePxWidth);
$heightTmpl = new PT($schema->imagePxHeight);
$idNmsp     = (string) $schema->namespaces->id;

$cfg  = new acdhOeaw\arche\lib\SearchConfig();
$cfg->metadataMode = $mode === 'image' ? '0_0_0_0' : '99999_99999_0_0';
$cfg->metadataParentProperty = $schema->nextItem;
$cfg->resourceProperties = [
    (string) $schema->nextItem,
    (string) $schema->parent,
    (string) $schema->id,
    (string) $schema->mime,
    (string) $schema->imagePxWidth,
    (string) $schema->imagePxHeight,
    RDF::RDF_TYPE,
];
if ($mode === 'manifest') {
    $cfg->resourceProperties[] = (string) $schema->label;
}
$cfg->relativesProperties = $cfg->resourceProperties;
$term = new SearchTerm(value: substr($id, strlen($repo->getBaseUrl())), type: SearchTerm::TYPE_ID);
$graph = $repo->getGraphBySearchTerms([$term], $cfg);

$getImgInfoUrl = fn($id) => $lorisBaseUrl . preg_replace('`^.*/`', '', $id) . "/info.json";

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
// Find the first resource
$first          = null;
$repoBaseUrl    = $repo->getBaseUrl();
$resolvedRes    = $graph->getSubject(new PT($schema->id, $id));
$firstRes       = $resolvedRes;
$collectionRes  = $resolvedRes;
$collectionTmpl = new PT($schema->parent, $collectionRes);
$tmpl           = new QT($resolvedRes, DF::namedNode(RDF::RDF_TYPE));
if ($graph->none($tmpl->withObject($schema->classes->collection)) && $graph->none($tmpl->withObject($schema->classes->topCollection))) {
    // iterate back over hasNextItem from the resolved resource
    // until the previous resource has the same parent as the resolved one
    $collectionRes = $graph->getObject(new QT($resolvedRes, $schema->parent));
    $collectionTmpl = new PT($schema->parent, $collectionRes);
    $change        = true;
    while ($change) {
        $change = false;
        foreach ($graph->listSubjects($nextTmpl->withObject($firstRes)) as $sbj) {
            if ($graph->any($collectionTmpl->withSubject($sbj))) {
                $firstRes = $sbj;
                $change = true;
            }
        }
    }
}
#echo "id: $id\nresolved: $resolvedRes\ncollection: $collectionRes\nfirst: $firstRes\n";
$sbj = $firstRes;
if ($mode === 'images') {
    $data = ['index' => null, 'images' => []];
    while ($sbj) {
        $tmp = $graph->copy(new QT($sbj));
        if (str_starts_with((string) $tmp->getObjectValue($mimeTmpl), 'image/')) {
            if ($resolvedRes->equals($sbj)) {
                $data['index'] = count($data['images']);
            } 
            $data['images'][] = $getImgInfoUrl((string) $sbj);
        }
        $sbj = null;
        foreach ($tmp->listObjects($nextTmpl) as $i) {
            if ($graph->any($collectionTmpl->withSubject($i))) {
                $sbj = $i;
            }
        }
    }
} else {
    $formatTitles = fn($x) => ['@value' => $x->getValue(), '@language' => $x->getLang()];
    $titles       = iterator_to_array($graph->listObjects($labelTmpl->withSubject($firstRes)));

    $canvases = [];
    while ($sbj) {
        $tmp  = $graph->copy(new QT($sbj));
        $mime = (string) $tmp->getObjectValue($mimeTmpl);
        if (str_starts_with($mime, 'image/')) {
            $infoUrl    = $getImgInfoUrl((string) $sbj);
            $width  = $tmp->getObjectValue($widthTmpl);
            $height = $tmp->getObjectValue($heightTmpl);
            if ($getDimensions && (empty($width) || empty($height) || empty($profile))) {
                $meta    = json_decode((string) @file_get_contents($infoUrl));
                if (is_object($meta)) {
                    $width   = $meta->width;
                    $height  = $meta->height;
                    $profile = reset($meta->profile);
                }
            }
            $canvases[] = [
                '@id'    => $sbj . '#IIIF-canvas',
                '@type'  => 'sc:Canvas',
                'label'  => array_map($formatTitles, iterator_to_array($tmp->listObjects($labelTmpl))),
                'height' => $height,
                'width'  => $width,
                'images' => [
                    [
                        '@id'        => $sbj . '#IIIF-annotation',
                        '@type'      => 'oa:Annotation',
                        'motivation' => 'sc:painting',
                        'on'         => $sbj . '#IIIF-canvas',
                        'resource'   => [
                            '@id'     => $infoUrl,
                            '@type'   => 'dctypes:Image',
                            'service' => [
                                '@context' => 'http://iiif.io/api/image/2/context.json',
                                '@id'      => preg_replace('`/[^/]*$`', '', $infoUrl),
                                'profile'  => $profile,
                            ],
                            'height' => $height,
                            'width'  => $width,
                            'format' => $mime,
                        ],
                    ],
                ],
            ];
	    }
        $sbj = null;
        foreach ($tmp->listObjects($nextTmpl) as $i) {
            if ($graph->any($collectionTmpl->withSubject($i))) {
                $sbj = $i;
            }
        }
    }

    $data = [
        '@context'    => 'http://iiif.io/api/presentation/2/context.json',
        '@id'         => $baseUrl . '?' . http_build_query($_GET),
        '@type'       => 'sc:Manifest',
        'label'       => array_map($formatTitles, $titles),
        'description' => ' ',
        'metadata'    => [],
        'sequences'   => [
            [
                '@id'      => $collectionRes . '#IIIF-Sequence',
                '@type'    => 'sc:Sequence',
                'canvases' => $canvases,
            ],
        ],
    ];
}

$data = json_encode($data, JSON_UNESCAPED_SLASHES);
if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    $data = gzencode($data);
    header('Content-Encoding: gzip');
}
header('Vary: Accept-Encoding');
header('Content-Type: application/json');
echo $data;

