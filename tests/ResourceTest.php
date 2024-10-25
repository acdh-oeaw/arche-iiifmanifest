<?php

/*
 * The MIT License
 *
 * Copyright 2021 zozlak.
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

namespace acdhOeaw\arche\iiifManifest\tests;

use quickRdf\DatasetNode;
use quickRdf\DataFactory as DF;
use quickRdfIo\Util as RdfIoUtil;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\iiifManifest\Resource as IiifResource;

/**
 * Description of ResourceTest
 *
 * @author zozlak
 */
class ResourceTest extends \PHPUnit\Framework\TestCase {

    const COLLECTION_URL = 'https://arche.acdh.oeaw.ac.at/api/1';
    const RESOURCE_URL   = 'https://arche.acdh.oeaw.ac.at/api/13';

    static private object $cfg;
    static private Schema $schema;

    static public function setUpBeforeClass(): void {
        self::$cfg    = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        self::$schema = new Schema(self::$cfg->iiifManifest->schema);
    }

    public function testModeImage(): void {
        $headers  = ['Location' => 'https://loris.acdh.oeaw.ac.at/13/info.json'];
        $expected = new ResponseCacheItem("Redirect to https://loris.acdh.oeaw.ac.at/13/info.json", 302, $headers, false);
        $this->assertEquals($expected, $this->getOutput(self::RESOURCE_URL, IiifResource::MODE_IMAGE));
    }

    public function testModeImages(): void {
        $expected = [
            'index'  => 1,
            'images' => [
                'https://loris.acdh.oeaw.ac.at/11/info.json',
                'https://loris.acdh.oeaw.ac.at/13/info.json',
            ]
        ];
        $this->checkOutput($expected, $this->getOutput(self::RESOURCE_URL, IiifResource::MODE_IMAGES));

        $expected['index'] = 0;
        $this->checkOutput($expected, $this->getOutput(self::COLLECTION_URL, IiifResource::MODE_IMAGES));
    }

    public function testModeManifest(): void {
        $expected = [
            '@context'    => 'http://iiif.io/api/presentation/2/context.json',
            '@id'         => 'https://arche-iiifmanifest.acdh.oeaw.ac.at/?',
            '@type'       => 'sc:Manifest',
            'label'       => [['@value' => 'collection 1', '@language' => 'en']],
            'description' => ' ',
            'metadata'    => [],
            'sequences'   => [
                [
                    '@id'      => 'https://arche.acdh.oeaw.ac.at/api/1#IIIF-Sequence',
                    '@type'    => 'sc:Sequence',
                    'canvases' => [
                        $this->getCanvas('https://arche.acdh.oeaw.ac.at/api/11', 'resource 1', 1234, 2345),
                        $this->getCanvas('https://arche.acdh.oeaw.ac.at/api/13', 'resource 3', null, null),
                    ]
                ],
            ]
        ];
        $this->checkOutput($expected, $this->getOutput(self::RESOURCE_URL, IiifResource::MODE_MANIFEST));
        $this->checkOutput($expected, $this->getOutput(self::COLLECTION_URL, IiifResource::MODE_MANIFEST));
    }

    private function checkOutput(array $expected, ResponseCacheItem $actual): void {
        $this->assertEquals(200, $actual->responseCode);
        $this->assertEquals(['Content-Type' => 'application/json'], $actual->headers);
        $this->assertFalse($actual->hit);
        $this->assertEquals($expected, json_decode($actual->body, true));
    }

    private function getOutput(string $resUrl, string $mode,
                               string $metaPath = __DIR__ . '/meta.ttl'): ResponseCacheItem {
        $repoRes = $this->getRepoResourceStub($resUrl, $metaPath);
        $iiifRes = new IiifResource($repoRes, self::$cfg->iiifManifest);
        return $iiifRes->getOutput($mode);
    }

    private function getRepoResourceStub(string $resUrl,
                                         string $metaPath = __DIR__ . '/meta.ttl'): RepoResourceInterface {
        $graph = new DatasetNode(DF::namedNode($resUrl));
        $graph->add(RdfIoUtil::parse($metaPath, new DF(), 'text/turtle'));

        $res = $this->createStub(RepoResourceInterface::class);
        $res->method('getUri')->willReturn($graph->getNode());
        $res->method('getGraph')->willReturn($graph);
        $res->method('getMetadata')->willReturn($graph);
        return $res;
    }

    /**
     * 
     * @return array<mixed>
     */
    private function getCanvas(string $url, string $label, ?int $width,
                               ?int $height): array {
        $id = preg_replace('`^.*/`', '', $url);
        return [
            '@id'    => $url . '#IIIF-canvas',
            '@type'  => 'sc:Canvas',
            'label'  => [
                ['@value' => $label, '@language' => 'en']
            ],
            'height' => $height,
            'width'  => $width,
            'images' => [
                [
                    '@id'        => $url . '#IIIF-annotation',
                    '@type'      => 'oa:Annotation',
                    'motivation' => 'sc:painting',
                    'on'         => $url . '#IIIF-canvas',
                    'resource'   => [
                        '@id'     => self::$cfg->iiifManifest->iiifServiceBase . $id . '/info.json',
                        '@type'   => 'dctypes:Image',
                        'service' => [
                            '@context' => 'http://iiif.io/api/image/2/context.json',
                            '@id'      => self::$cfg->iiifManifest->iiifServiceBase . $id,
                            'profile'  => 'http://iiif.io/api/image/2/level2.json',
                        ],
                        'height'  => $height,
                        'width'   => $width,
                        'format'  => 'image/tiff',
                    ],
                ]
            ],
        ];
    }
}
