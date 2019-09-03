## Basic Goal
Hook WP into the Rackspace CDN (Cloud Files) as seamlessly as possible

## Rackspace API and cURL
* Making use of two components of the Rackspace skynet: Cloud Files and Cloud Queues
* Shared `NMURackspaceAuth` object to get tokens, service URLs and, for queues, Client ID

## The WP hooks involved
* adding post meta to queue up files for transfer or deletion
* filtering content to replace local URLs with CDN URLs
* url rewriting checks for local files first, that haven't been uploaded to CDN, before rewriting URL to serve from the CDN

## The background worker
* polls the queue, moves the files, erases local copy, waits 10 seconds
* worker needs to be up and running on each app server instance to insure files uploaded to it make it into the CDN before the instance is destroyed; while there is always at least one app server, there's no way to guarantee which servers will be culled while scaling down
* when spinning up new app server, the queue worker should be set up first as it creates the queue, the WP filters assume the queue is ready to receive messages and will fail if nothing is set up before hand
* each worker/server has its own queue name defined as a constant that is `'nmu_cdn_' . gethostname()`
* cloudfiles-worker.conf defines an upstart job; this should be moved to /etc/init and started with the command `sudo start couldfiles-worker` and cloudfiles-worker.php needs to be made executable

## Cleanup
* cleanup script, for use at the command line, catches any files that got missed by the worker; to be used before shutting down an app server instance

autodeploy test