# Code Samples
Contact: edward@edwardficklin.com

## Laravel
Project: Counterstream Radio
Broadcast automation for a webradio station; built with Laravel, Shoutcast streaming server, and Liquidsoap for transcoding mp3s. 

The two samples here focus on the interaction between Laravel framework and the Liquidsoap daemon process. The first step was wrapping the command-line interface of Liquidsoap in an Artisan command for both easy console access and usage within other parts of the framework. From there, the StreamController class manages the queueing up of tracks from a playlist/schedule and issuing a push notification (via the Pusher service) telling clients to update the display of what's currently playing.

To listen to Counterstream Radio: https://www.newmusicusa.org?counterstream=playing  
To learn more about Liquidsoap: https://www.liquidsoap.info/

## Vue
Project: newmusicusa.org, media on demand player
Note: with contributions from Eileen Mack, Platform Strategist and Software Engineer, New Music USA

## Wordpress
Project: newmusicusa.org
Collection of intergration plugins; leveraging third-party services in a self-contained manner; often slimmer versions of what's available from the WP community or the vendors themselves, tailored to provide only our needs