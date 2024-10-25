<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
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

namespace acdhOeaw\arche\iiifManifest;

use Psr\Log\LoggerInterface;
use zozlak\RdfConstants as RDF;
use rdfInterface\DatasetInterface;
use rdfInterface\DatasetNodeInterface;
use rdfInterface\TermInterface;
use rdfInterface\LiteralInterface;
use quickRdf\DataFactory as DF;
use quickRdf\NamedNode;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;

class Resource {

    const MODE_IMAGE    = 'image';
    const MODE_IMAGES   = 'images';
    const MODE_MANIFEST = 'manifest';

    /**
     * @param array<mixed> $param
     */
    static public function cacheHandler(RepoResourceInterface $res,
                                        array $param, object $config,
                                        ?LoggerInterface $log = null): ResponseCacheItem {

        $iiifRes = new self($res, $config, $log);
        return $iiifRes->getOutput(...$param);
    }

    private DatasetNodeInterface $meta;
    private object $config;
    private Schema $schema;
    private LoggerInterface | null $log;

    public function __construct(RepoResourceInterface $res, object $config,
                                ?LoggerInterface $log = null) {
        $this->meta   = $res->getGraph();
        $this->config = $config;
        $this->schema = new Schema($config->schema);
        $this->log    = $log;
    }

    public function getOutput(string $mode): ResponseCacheItem {
        if (!in_array($mode, [self::MODE_IMAGE, self::MODE_IMAGES, self::MODE_MANIFEST])) {
            throw new IiifException("Unknown mode $mode", 400);
        }

        if ($mode === 'image') {
            $location = $this->getImageInfoUrl((string) $this->meta->getNode());
            $headers  = ['Location' => $location];
            return new ResponseCacheItem("Redirect to $location", 302, $headers, false);
        }

        list($firstRes, $collectionRes) = $this->findFirstResource();

        $graph = $this->meta->getDataset();
        $sbj   = $firstRes;
        $data  = match ($mode) {
            self::MODE_IMAGES => $this->getImageList($firstRes, $collectionRes),
            self::MODE_MANIFEST => $this->getManifest($firstRes, $collectionRes),
        };
        return new ResponseCacheItem($data, 200, ['Content-Type' => 'application/json'], false);
    }

    private function getImageInfoUrl(string $id): string {
        return $this->config->iiifServiceBase . preg_replace('`^.*/`', '', $id) . "/info.json";
    }

    /**
     * 
     * @return array<string, string>
     */
    private function getManifestTitle(LiteralInterface $x): array {
        return ['@value' => $x->getValue(), '@language' => $x->getLang()];
    }

    /**
     * 
     * @return array{0: TermInterface, 1: TermInterface}
     */
    private function findFirstResource(): array {
        $graph      = $this->meta->getDataset();
        $nextTmpl   = new PT($this->schema->nextItem);
        $parentTmpl = new PT($this->schema->parent);

        $resolvedRes   = $this->meta->getNode();
        $firstRes      = $resolvedRes;
        $collectionRes = $resolvedRes;

        $collectionTmpl         = new PT($this->schema->parent, $collectionRes);
        $collectionClassTmpl    = new QT($resolvedRes, DF::namedNode(RDF::RDF_TYPE), $this->schema->classes->collection);
        $topCollectionClassTmpl = new QT($resolvedRes, DF::namedNode(RDF::RDF_TYPE), $this->schema->classes->topCollection);
        if ($graph->none($collectionClassTmpl) && $graph->none($topCollectionClassTmpl)) {
            // iterate back over hasNextItem from the resolved resource
            // until the previous resource has the same parent as the resolved one
            $collectionRes  = $graph->getObject($parentTmpl->withSubject($resolvedRes));
            $collectionTmpl = $parentTmpl->withObject($collectionRes);
            $change         = true;
            while ($change) {
                $change = false;
                foreach ($graph->listSubjects($nextTmpl->withObject($firstRes)) as $sbj) {
                    if (!$sbj->equals($firstRes) && $graph->any($collectionTmpl->withSubject($sbj))) {
                        $firstRes = $sbj;
                        $change   = true;
                    }
                }
            }
        }
        $this->log?->info("resolved: $resolvedRes collection: $collectionRes first: $firstRes");

        // for better caching
        //$node = $this->meta->getNode();
        //$graph->add(DF::quad($node, $this->schema->id, $firstRes));
        //$graph->add(DF::quad($node, $this->schema->id, $collectionRes));

        return [$firstRes, $collectionRes];
    }

    private function getImageList(TermInterface $firstRes,
                                  TermInterface $collectionRes): string {
        $mimeTmpl       = new PT($this->schema->mime);
        $nextTmpl       = new PT($this->schema->nextItem);
        $collectionTmpl = new PT($this->schema->parent, $collectionRes);
        $graph          = $this->meta->getDataset();
        $resolvedRes    = $this->meta->getNode();

        $data = ['index' => null, 'images' => []];
        $sbj  = $firstRes;
        while ($sbj) {
            $tmp = $graph->copy(new QT($sbj));
            if (str_starts_with((string) $tmp->getObjectValue($mimeTmpl), 'image/')) {
                if ($resolvedRes->equals($sbj)) {
                    $data['index'] = count($data['images']);
                }
                $data['images'][] = $this->getImageInfoUrl((string) $sbj);
            }
            $sbj = $this->getNextSbj($tmp, $collectionTmpl);
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function getManifest(TermInterface $firstRes,
                                 TermInterface $collectionRes): string {
        $labelTmpl      = new PT($this->schema->label);
        $mimeTmpl       = new PT($this->schema->mime);
        $widthTmpl      = new PT($this->schema->imagePxWidth);
        $heightTmpl     = new PT($this->schema->imagePxHeight);
        $collectionTmpl = new PT($this->schema->parent, $collectionRes);
        $graph          = $this->meta->getDataset();

        /** @var array<LiteralInterface> $titles */
        $titles = iterator_to_array($graph->listObjects($labelTmpl->withSubject($collectionRes)));

        $canvases = [];
        $sbj      = $firstRes;
        while ($sbj) {
            $profile = $this->config->profile;
            $tmp     = $graph->copy(new QT($sbj));
            $mime    = (string) $tmp->getObjectValue($mimeTmpl);
            if (str_starts_with($mime, 'image/')) {
                $infoUrl = $this->getImageInfoUrl((string) $sbj);
                $width   = $tmp->getObjectValue($widthTmpl);
                $height  = $tmp->getObjectValue($heightTmpl);
                if ($this->config->fetchDimensions && (empty($width) || empty($height) || empty($profile))) {
                    $meta = json_decode((string) @file_get_contents($infoUrl));
                    if (is_object($meta)) {
                        $width   = $meta->width;
                        $height  = $meta->height;
                        $profile = reset($meta->profile);
                    }
                }
                /** @var array<LiteralInterface> $labels */
                $labels     = iterator_to_array($tmp->listObjects($labelTmpl));
                $canvases[] = [
                    '@id'    => $sbj . '#IIIF-canvas',
                    '@type'  => 'sc:Canvas',
                    'label'  => array_map(fn($x) => $this->getManifestTitle($x), $labels),
                    'height' => !empty($height) ? (int) $height : null,
                    'width'  => !empty($width) ? (int) $width : null,
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
                                'height'  => $height,
                                'width'   => $width,
                                'format'  => $mime,
                            ],
                        ],
                    ],
                ];
            }
            $sbj = $this->getNextSbj($tmp, $collectionTmpl);

            $data = [
                '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                '@id'         => $this->config->baseUrl . '?' . http_build_query($_GET),
                '@type'       => 'sc:Manifest',
                'label'       => array_map(fn($x) => $this->getManifestTitle($x), $titles),
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

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function getNextSbj(DatasetInterface $data, PT $collectionTmpl): TermInterface | null {
        $nextTmpl = new PT($this->schema->nextItem);
        $node     = $this->meta->getNode();
        $graph    = $this->meta->getDataset();

        $sbj = null;
        foreach ($data->listObjects($nextTmpl) as $i) {
            if ($graph->any($collectionTmpl->withSubject($i))) {
                $sbj = $i;
                // for better caching
                //$graph->add(DF::quad($node, $this->schema->id, $i));
            }
        }
        return $sbj;
    }
}
