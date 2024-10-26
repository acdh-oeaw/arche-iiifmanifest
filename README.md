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

See the .github/workflows/deploy.yaml
