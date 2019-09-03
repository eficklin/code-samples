# Code Samples
Contact: edward@edwardficklin.com

## Laravel
Project: Counterstream Radio  
Broadcast automation for a webradio station; built with Laravel, Shoutcast streaming server, and Liquidsoap for transcoding mp3s. 

The two samples here focus on the interaction between the Laravel framework and the Liquidsoap daemon process. The first step was wrapping the command-line interface of Liquidsoap in an Artisan command for both easy console access and usage within other parts of the framework. From there, the StreamController class manages the queuing up of tracks from a playlist/schedule and issuing a push notification (via the Pusher service) telling clients to refresh their displays of current and recently played tracks.

To listen to Counterstream Radio: https://www.newmusicusa.org?counterstream=playing  
To learn more about Liquidsoap: https://www.liquidsoap.info/

## Vue
Project: newmusicusa.org, media on demand player  
Note: with contributions from Eileen Mack, Platform Strategist and Software Engineer, New Music USA

## Wordpress
Project: newmusicusa.org  
A collection of integration plugins that leverage third-party services in a self-contained manner. These are slimmer versions of what's available from the WP community or the vendors themselves, tailored to provide only our needs rather than for general distribution.

- Mandrill: transactional email delivery service; the plugin overrides WP default mail delivery with API calls to the Mandrill service; also includes a command line script for generating transactional emails in bulk independent of the WP framework
- Cloudfiles: cloud storage service (with optional CDN) from Rackspace; the plugin ties into the file upload process to copy files into the storage service so that app servers can come and go as needed (our approach to scaling WP) without worrying about the local uploads directory
- Uservoice: customer support ticketing service; the plugin inserts the Uservoice contact widget discreetly into the footer after making a call to the UV service for a single sign-on token to correctly match up WP users with UV tickets

Finally, we have a plugin defining a very lightweight, database backed queue service; for our distributed setup, this proved a better way of managing background processes and jobs than WordPress's built-in cron system and more efficient than network calls to a queue service.