# ARCHE-IIIF-manifest

[![Build status](https://github.com/acdh-oeaw/arche-iiifmanifest/actions/workflows/deploy.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-iiifmanifest/actions/workflows/deploy.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-iiifmanifest/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-iiifmanifest?branch=master)

A dissemination service for the [ARCHE Suite](https://acdh-oeaw.github.io/arche-docs/) providing IIIF manifests for repository resources.

* Allows to limit supported resource URL namespaces
* As for now **doesn't** support caching.
  It means generation of the full manifest may take a long time for long image sequences.

## REST API

`{deploymentUrl}?id={URL-encoded resource URL}&mode=[image|images|manifest]`

Depending on the `mode` parameter the response contains:

* `image` a single [image information](https://iiif.io/api/image/2.1/#image-information) metadata
  (precisely the service returns a redirect to the Loris service endpoint returning the metadata).
* `images` a JSON array of [image information](https://iiif.io/api/image/2.1/#image-information) metadata URLs
  for all images within a sequence along with the index of the requested image within the array.
  This format is accepted e.g. by the [OpenSeadragon image viewer](https://openseadragon.github.io/examples/tilesource-iiif/) (see the last example).
* `manifest` a full IIIF [presentation API manifest](https://iiif.io/api/presentation/2.1/#manifest).

## Deployment

* Build the docker image providing the runtime environment
  ```bash
  docker build -t arche-iifmanifest .
  ```
* Run a docker container mounting the arche-core data dir under `/data` and specyfying the configuration using env vars, e.g.:
  ```bash
  docker run --name arche-exif -p 80:80 \
      -e LORIS_BASEL=https://loris.acdh.oeaw.ac.at/ \
      -e 'ALLOWEDNMSP=https://arche-curation.acdh.oeaw.ac.at/api/,https://arche-dev.acdh-dev.oeaw.ac.at/api/' \
      -v pathToArcheDataDir:/data \
      arche-exif
  ```
  available configuration env vars:
  * `LORIS_BASE`: base URL of the Loris image server
  * `ALLOWED_NMSP`: comma-separated list of namespaces (URI prefixes) allowed to be downloaded
  * `DEFAULT_MODE`: default mode to be used when not specified in the request (`image`, `images` or `manifest`, by default 'image')
  * `GET_DIMENSIONS`: when set to `true`, the service tries to fetch image dimensions from the IIIF service if dimensions are missing in the metadata
* Test
  ```bash
  curl -i http://127.0.0.1/?id=someResourceId&mode=images
  ```


