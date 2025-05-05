# ARCHE-IIIF-manifest

[![Build status](https://github.com/acdh-oeaw/arche-iiifmanifest/actions/workflows/deploy.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-iiifmanifest/actions/workflows/deploy.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-iiifmanifest/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-iiifmanifest?branch=master)

A dissemination service for the [ARCHE Suite](https://acdh-oeaw.github.io/arche-docs/) providing IIIF 2.1 manifests for repository resources.

## REST API

`{deploymentUrl}?id={URL-encoded resource URL}&mode=[image|images|manifest|collection|auto]`

Depending on the `mode` parameter the response contains:

* `image` (default) a single [image information](https://iiif.io/api/image/2.1/#image-information) metadata
  (precisely the service returns a redirect to the Loris service endpoint returning the metadata).
* `images` a JSON array of [image information](https://iiif.io/api/image/2.1/#image-information) metadata URLs
  for all images within a sequence along with the index of the requested image within the array.
  This format is accepted e.g. by the [OpenSeadragon image viewer](https://openseadragon.github.io/examples/tilesource-iiif/) (see the last example).
  * When this mode is used on a binary resource, the returned data describe content of its parent repository resource.
* `manifest` a full IIIF 2.1 [presentation manifest](https://iiif.io/api/presentation/2.1/#manifest).
  * When this mode is used on a binary resource, a manifest of a parent repository resource is returned.
* `collection` a IIIF 2.1 [collection manifest](https://iiif.io/api/cookbook/recipe/0032-collection/) listing (sub) collections of a requested repository resource.
  * When this mode is used on a binary resource, a collection manifest pointing to the presentation manifest of a parent repository resource is returned.

## Deployment

See the .github/workflows/deploy.yaml
