<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Log;
use DB;
use Artisan;
use File;

use Carbon\Carbon;

use App\Http\Requests;
use App\Track;
use App\Broadcast;
use App\Playlist;



use Vinkla\Pusher\PusherManager;

class StreamController extends Controller
{
    protected $pusher;

    public function __construct(PusherManager $pusher) {
        $this->pusher = $pusher;
    }

    /**
     * display stream status
     */
    public function index(Request $request) {
        if ($request->ajax()) {
            //playlist with id, dropped, drift and collection of tracks
            $out = array();

            $out['currentStatus'] = Broadcast::current() ? 'ok' : 'error';

            $playlist = Playlist::current();

            if ($playlist) {
                $remaining = 
                    $playlist->tracks()
                        ->where('order', '>=', $playlist->current_position)
                        ->get()
                        ->map(function($track) {
                            return [
                                'url' => 'track/' . $track->id . '/edit',
                                'title' => $track->title,
                                'artist' => $track->artist->name,
                                'album' => $track->album->title,
                                'duration' => $track->getDurationDisplay()
                            ];
                        });

                $out['playlist']['tracks'] = $remaining;
                $out['playlist']['id'] = $playlist->id;
                $out['playlist']['date'] = $playlist->date;

                if (Carbon::now()->lt(new Carbon($playlist->date))) {
                    $out['playlist']['warning'] = "Danger, Will Robinson, we've skipped ahead!";
                }
            }

            if ($request->has('callback')) {
                return response()->json($out)->setCallback($request->input('callback'));
            } else {
                return response()->json($out);
            }
        } else {   
            Artisan::call('stream:status');
            $status = Artisan::output();
            return view('stream.index', ['stream_status' => $status]);
        }
    }

    /**
     * Get the next song to be played by liquidsoap.
     * @return string (in the format of a liquidsoap request uri with an annotation)
     */
    public function next_track() {
        $playlist = Playlist::current();

        if ($playlist) {
            $track = $playlist->getNextTrack();
        } else {
            return;
        }
        
        if ($track) {
            $file_path = $track->getFullPath();
            $liquidsoap_request = 'annotate:track_id="' . $track->id . '":' . $file_path . "\n";
        } 

    	return $liquidsoap_request;
    }

    /**
     * Record the track being played by liquidsoap
     * fire new track event 
     */
    public function track_broadcasted($id) {
        $track = Track::find($id);
        $track->broadcasts()->save(new Broadcast);

        $track_info = $this->_get_track_info($id, true);

        $out = array(
            'stream_url' => 'http://counterstream.newmusicusa.org:8000',
            'web_url' => 'https://www.newmusicusa.org/counterstream-radio',
            'TrackInfo' => $track_info,
            'ends_at' => Broadcast::current()->shouldEndAt()->format("g:i:s a")
        );

        File::put(public_path() . '/current_track', 'current(' . json_encode($out) . ')');

        $recent = Broadcast::orderBy('created_at', 'desc')->take(10)->get();

        $tracks = array();

        foreach ($recent as $i => $b) {
            if ($i == 0 || $b->track->type != Track::TRACK_TYPE_MUSIC)
                continue;

            $track_info = array(
                'Composer' => $b->track->artist->name,
                'Track' => $b->track->title,
                'ID' => $b->track->id,
                'Duration' => $b->track->duration, 
            );
            $tracks[] = $track_info;
        }

        $out = array(
            'stream_url' => 'http://localhost:8000',
            'web_url' => 'https://www.newmusicusa.org/counterstream-radio',
            'Tracks' => $tracks
        );

        File::put(public_path() . '/recent_tracks', 'recent(' . json_encode($out) . ')');

        if ($track->type == Track::TRACK_TYPE_MUSIC) {    
            $this->pusher->trigger(
                'now-playing', 
                'new-track', 
                [
                    'id' => $track->id,
                    'title' => $track->title, 
                    'artist' => $track->artist->name, 
                    'album' => $track->album->title, 
                    'ends_at' => Broadcast::current()->shouldEndAt()->format("H:i:s")
                ]
            );
        }
    }

    /**
     * Return full details for a given track (including art)
     * @return string JSON object
     */
    public function track_detail(Request $request, $id) {
        $track_info = $this->_get_track_info($id);

        $out = array(
            'stream_url' => 'http://counterstream.newmusicusa.org:8000',
            'web_url' => 'https://www.newmusicusa.org/counterstream-radio',
            'TrackInfo' => $track_info
        );

        if ($request->has('callback')) {
            return response()->json($out)->setCallback($request->input('callback'));
        } else {
            return response()->json($out);
        }
    }

    /**
     * Send tweet with current track and shortlink
     */
    public function tweet_current_track() {
        $track = Broadcast::current()->track->title . " by " . Broadcast::current()->track->artist->name;
        
        if (strlen($track) > 80) {
            $track = substr($track, 0, 77) . '...';
        }
        
        $track = urlencode($track);
        
        $tweet = "Now on Counterstream Radio: {$track} #nowplaying http://bit.ly/1z9qkdM";
        
        \Codebird\Codebird::setConsumerKey(env('TW_CONSUMER_KEY'), env('TW_CONSUMER_SECRET'));
        $cb = \Codebird\Codebird::getInstance();
        $cb->setToken(env('TW_ACCESS_TOKEN'), env('TW_ACCESS_SECRET'));
        
        $reply = $cb->statuses_update("status={$tweet}");

        if (isset($reply->errors)) {
            foreach ($reply->errors as $e) {
                Log::error($e->code . ": " . $e->message);
            }
        }
    }

    /**
     * send skip command to Liquidsoap
     */
    public function skip_track() {
        Artisan::call('stream:liquidsoap', ['ls_command' => 'output(dot)shoutcast.skip']);
        $output = Artisan::output();

        if (starts_with($output, 'Done')) {
            return response()->json(['status' => 'done']);
        } else {
            Log::error("Skip track command returned: " . $output);
            return response()->json(['error' => $output], 400);
        }
    }

    /**
     * send puase (stop) command to Liquidsoap
     */
    public function pause_stream() {
        Artisan::call('stream:liquidsoap', ['ls_command' => "output(dot)shoutcast.stop"]);
        $output = Artisan::output();

        if (starts_with($output, 'OK')) {
            return response()->json(['status' => 'ok']);
        } else {
            Log::error("Pause stream command returned: " . $output);
            return response()->json(['error' => $output], 400);
        }
    }

    public function resume_stream() {
        Artisan::call('stream:liquidsoap', ['ls_command' => 'output(dot)shoutcast.start']);
        $output = Artisan::output();

        if (starts_with($output, 'OK')) {
            return response()->json(['status' => 'ok']);
        } else {
            Log::error("Resume stream command returned: " . $output);
            return response()->json(['error' => $output], 400);
        }
    }

    /**
     * helper for fetching the track info, with or without art
     * @param int the Track id
     * @param bool include cover art?
     * @return array
     */
    private function _get_track_info($id, $with_art = true) {
        $track = Track::find($id);

        if ($track->type == Track::TRACK_TYPE_STATIONID) {
            return ['Track' => 'Counterstream Radio Station ID'];
        }

        $track_info = array(
            'Composer' => $track->artist->name,
            'Track' => $track->title,
            'ID' => $track->id,
            'Album' => $track->album->title,
            'Year' => $track->composed,
            'Label' => $track->album->label->name,
            'Composed' => $track->composed,
            'Performers' => $track->performers,
            'Duration' => $track->getDurationDisplay()
        );

        if ($with_art) {
            if ($track->cover_art) {
                $track_info['CoverArt'] = $track->cover_art;
            }

            if ($track->az_detail_page) {
                $track_info['AmazonDetailPage'] = $track->az_detail_page;
            }
        }
        
        return $track_info;
    }
}
