let featuredNames = {
	methods: {
		getFeaturedNames: function(features, withLinks) {
			if (features.length == 1) {
				if (withLinks) {
					return `<a href="${features[0].link}" target="_blank">${features[0].name}</a>`;
				} else {
					return features[0].name;
				}
			}

			if (features.length == 2) {
				if (withLinks) {
					return `<a href="${features[0].link}" target="_blank">${features[0].name}</a> and <a href="${features[1].link}" target="_blank">${features[1].name}</a>`;
				} else {
					return `${features[0].name} and ${features[1].name}`;
				}
			}

			var out = '';
			for (var i = 0; i < features.length; i++) {
				var name = '';
				if (withLinks) {
					name = `<a href="${features[i].link}" target="_blank">${features[i].name}</a>`;
				} else {
					name = features[i].name;
				}

				if (i == features.length - 1) {
					out += 'and ' + name; 
				} else {
					out += name + ', ';
				}
			}

			return out;
		}
	}
}

let share = {
	data: function() {
		return {
			share: {
				recipient: '',
				message: '',
				shareLink: ''
			},
			notice: '',
			twText: 'These are a few of my favorite things.'
		}
	},
	methods: {
		openShare: function() {
			jQuery('#playlist .share').fadeIn();
			this.gaClick('Open',nmu.currentUserID);
		},
		close: function() {
			jQuery('#playlist .share').fadeOut();
			this.gaClick('Close',nmu.currentUserID);
			jQuery('.share .message').hide();
			this.notice = '';
			this.share.recipient = '';
			this.share.message = '';
		},
		fb: function(url) {
			window.open('https://www.facebook.com/sharer/sharer.php?u=' + url, '', 'width=626,height=436');
		},
		tw: function(url) {
			window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(this.twText) + '&url=' + url, '', 'width=626,height=436');
		},
		copy: function() {
			document.querySelector('#share-link').select();
			var ok = document.execCommand('copy');
			if (ok) {
				jQuery('.link-copy').fadeOut('fast', function() {jQuery('.link-copied').fadeIn('fast')});				
				window.setTimeout(function() { jQuery('.link-copied').fadeOut('fast', function() {jQuery('.link-copy').fadeIn('fast')}) }, 3000);
			}
		},
		email: function(url) {
			this.share.shareLink = url;
			jQuery('.share .message').fadeIn();
		},
		sendMessage: function() {			
			let data = { email: this.share.recipient, message: this.share.message, link: this.share.shareLink, action: 'share_playlist_message'}
			jQuery.ajax(
				nmu.adminAjax, {data: data, method: 'POST', dataType: 'json'}
			).done(function(resp) {
				if (resp.status == 'success') {
					this.notice = 'Success! Message sent.';
					setTimeout(() => { //arrow function so we can get to the correct this
			            this.notice = '';
						jQuery('.share .message').fadeOut();
						this.share.recipient = '';
						this.share.message = '';
			        }, 3000);
				} else {
					this.notice = `Uh oh! ${resp.message}`;
				}
			}.bind(this)).fail(function() {
				this.notice = "Something seems to have gone wrong.";
			});
		},
		gaClick: function(action, playlistID) {
			//console.log('gaClick ' + 'Share: ' + action + ` playlist id ${playlistID}`);
			if (typeof gtag !== 'undefined') {
				gtag('event', 'Share: ' + action, {event_category: 'Playlist', event_label: `playlist id ${playlistID}`});
			}
		}
	}
}

let gaEvents = {
	data: function() {
		return {
			elapsedTime: 0
		}
	},
	computed: {

	},
	methods: {
		gaPlaylistLabel: function() {
			if(this.currentItem.vocab){ //seed generated playlist
				return this.currentItem.vocab + ': ' + this.currentItem.slug;
			} else if(this.currentList){
				return 'shared playlist ' + this.currentList;
			} else { //own playlist in mod dropdown
				$playlistID = nmu.currentUserID > 0 ? nmu.currentUserID : nmu.sharedPlaylist;
				return 'own playlist id ' + $playlistID;
			}
		},
		gaTrackLabel: function() {
			return this.gaPlaylistLabel() + ' | ' + this.currentItem.details.id + ' ' + this.currentItem.details.postedBy + ': ' + this.currentItem.details.title + ' - ' + this.currentItem.service;
		},
		gaEvent(action, label, val = 0, category = 'Playlist'){
			if (typeof gtag !== 'undefined') {
				//console.log('gaEvent ' + action + " " + label + val);
				if(val){
					gtag('event', action, {event_category: category, event_label: label, value: val});
				}
				else {
					gtag('event', action, {event_category: category, event_label: label});
				}
			}
		},
		gaStart: function() {
			this.gaEvent('Start Track', this.gaTrackLabel());
		},
		gaFinish: function(duration) {
			this.gaEvent('Finish Track', this.gaTrackLabel(), duration);
		},
		gaInterrupted: function(item) {
			if (typeof gtag !== 'undefined') {
				let action = 'Interrupt Track';
				let label = this.gaTrackLabel();
				this.gaEventWithDuration(action, label, item);
			}
		},
		gaEventWithDuration: function(action = 'Interrupt Track', label, item = this.currentItem) {
			if (typeof gtag !== 'undefined') {
				let label = this.gaTrackLabel();
				switch (item.service) {
					case 'vimeo':
						item.widget.getCurrentTime().then((seconds) => {
							this.gaEvent(action, label, Math.ceil(seconds));
						});
						break;
					case 'soundcloud':
						action += 'soundcloud';
						item.widget.getPosition((msec) => {
							seconds = Math.ceil(msec / 1000);
							this.gaEvent(action, label, seconds);
						});
						break;
					default: //youtube or mp3
						if (typeof gtag !== 'undefined' && typeof item.widget.getCurrentTime !== 'undefined') {
							this.gaEvent(action, label, Math.ceil(item.widget.getCurrentTime()));
						}
						break;
				}
			}
		},
		gaPause: function(item) {
			this.gaEventWithDuration('Pause Track', this.gaTrackLabel(), item);
		},
		gaResume: function(item) {
			this.gaEvent('Resume Track', this.gaTrackLabel());
		},
	}
}

function triggerOpenCS() {
	jQuery('html, body').animate({ scrollTop: 0 }, "slow");
	if (jQuery('section.media-experience .handle.broadcast').hasClass('active')){ //if already has active class for some reason don't want to close it, just make sure right section is showing
		$('#playlist').slideUp();
		$('#counterstream').slideDown();
	} else {
		jQuery('section.media-experience .handle.broadcast').trigger('click');
	}	
}

function triggerOpenMoD() {
	jQuery('html, body').animate({ scrollTop: 0 }, "slow");
	if (jQuery('section.media-experience .handle.list').hasClass('active')){ //if already has active class for some reason don't want to close it, just make sure right section is showing
		$('#counterstream').slideUp();
		$('#playlist').slideDown();
	} else {
		jQuery('section.media-experience .handle.list').trigger('click');
	}	
}

let modPlaylistFunctions = {
	data: function() {
		return {
			taxonomy: {
				artistsVoice: [],
				instrument: [],
				lists: []
			},
			similar: [],
			toggled: 'explore',
			looping: true,
			shuffling: false,
		}
	},
	computed: {
		inList: function() {
			for (item of this.playlistItems) { //in this context playListItems = this user's playlist
				if (this.currentItem.details.id == item.id)
					return true;
			}
		}
	},
	methods: {
		initSetup: function() {
			this.getUserPlaylist();
			this.getTaxonomy();			
		},
		addToPlaylist: function(id, nonce) {
			$.get(
				nmu.adminAjax,
				{ action: 'add_to_playlist', postID: id, nonce: nonce }
			).done(function(resp){
				if (resp == 'success') {
					this.playlistMessage = "Added to your list!";
					this.gaEvent('Added to Playlist ' + nmu.currentUserID, this.gaTrackLabel());
					this.getUserPlaylist(); //update own playlist - different from basic
				}
			}.bind(this)).fail(function(resp){

			}.bind(this));
		},
		getUserPlaylist: function() {
			$.getJSON(
				nmu.adminAjax,
				{ action: 'get_playlist_for_user' }
			).done(function(resp){
				if (resp instanceof Array) {
					this.playlistItems = resp;
				}
			}.bind(this));
		},
		getTaxonomy: function() {
			$.getJSON(
				nmu.adminAjax,
				{action: 'player_taxonomy'}
			).done(function(resp){
				this.taxonomy = resp;
			}.bind(this)).fail(function(resp) {

			}.bind(this));
		},
		getItemByTerms: function(slug, vocab) { //called from list of playlists on front end
			var data = { action: 'tag_play', vocab: vocab, slug: slug};
			this.getItem(data);
			if (typeof gtag != 'undefined') {
				let gtagLabel = `${vocab}: ${slug}`;
				let gtagAction = 'Playlist Click';
				if(jQuery(event.target).parents('div.featured').length){
					gtagAction = 'Featured ' + gtagAction;
				} 
				this.gaEvent(gtagAction, gtagLabel);
			}
		},
		next: function() { //next item button, only for slug lists
			if (this.currentItem.slug) {
				var data = {action: 'tag_play', id: this.similar.shift(), vocab: this.currentItem.vocab, slug: this.currentItem.slug };
				this.getItem(data);
				this.gaEvent('Next Playlist Item',this.gaTrackLabel());
			}
		},
		advance: function() {
			if (this.currentItem.slug) { //indicates we're on a generated list, so we can always get another... appears to loop back to beginning even on a finite list
				this.next();
			} else {
				if (this.looping) { //only happens when we're on our own list
					this.getItemById(this.playlistItems[this.playlistPosition].id);
				} else {
					if (this.playlistPosition == -1) {
						//computed prop returns -1 when looping off and end of list reached
						this.$emit('endOfList');
						return;
					} else {
						this.getItemById(this.playlistItems[this.playlistPosition].id);
					}
				}
			}
			this.gaEvent('Advance Track',this.gaTrackLabel());
		},
		edit: function() {
			$('.playlist-item .dashicons').toggle();
			if (jQuery('.playlist-item .dashicons.dashicons-move').is(':visible')) {
			    this.gaEvent('Open Edit Playlist','playlist id ' + nmu.currentUserID);
			} else {
			    this.gaEvent('Close Edit Playlist','playlist id ' + nmu.currentUserID);
			}					
		},
		removeItem: function(id, nonce) {
			$.getJSON(
				nmu.adminAjax, {action: 'remove_from_playlist', postID: id, nonce: nonce}
			).done(function(resp){
				if (resp.status == 'success') {
					this.playlistMessage = 'Item removed.';
					this.getUserPlaylist();
				}
			}.bind(this)).fail(function(resp){
				this.playlistMessage = "There was a problem, please try again.";
			}.bind(this));
		},
		toggle: function(section) {
			this.toggled = section;
			this.gaEvent('Toggle Playlist Tab',section);
		},
		highlightSelected: function(item, oldVal) {
			//this overrides the main component method
				if (oldVal.vocab) { //basic will never have vocab????
					this.taxonomy[oldVal.vocab].forEach((e) => { e.active = false; }); //what is happening here? we're selecting a playlist from the list
				} else {
					this.playlistItems.forEach((e) => { e.active = false; }); //or selecting a playlist item
				}

				if (item.vocab) { //basic will never have vocab????
					this.taxonomy[item.vocab].forEach(function(e){
						if (item.slug == e.slug) {
							e.active = true;
						}
					});
				} else {
					this.playlistItems.forEach(function(e) {
						if (item.details.id == e.id) {
							e.active = true;
						}
					});
				}
			}
	},
	watch: {
		looping: function(newVal, oldVal) {
			this.gaEvent('Toggle Looping', this.gaPlaylistLabel());
		},
		toggled: function(newVal, oldVal) {
			$('#' + oldVal).fadeOut('fast', function() {
				$('#' + newVal).fadeIn('fast');
			});
		},
		playlistItems: function(newVal, oldVal) {
			var playlistOrder = new Array();
			newVal.forEach((e) => { playlistOrder.push(e.id) });
			$.getJSON(
				nmu.adminAjax, {action: 'update_playlist_order', playlist_ids: playlistOrder }
			).done(function(resp){
				if (resp.status == 'success') {
					this.playlistMessage = "Playlist updated!";
				}
			}.bind(this)).fail(function(resp){
				this.playlistMessage = "Sorry, something went wrong, give it another try.";
			}.bind(this));
		},
	}
}

let basicPlaylist = {
		el: "#playlist",
		data: function(){
			return {
				playlistMessage: '',
				playlistItems: [],
				currentItem: {
					embed: '',
					details: {
						features: []
					},
					widget: {}
				},
				playing: false,
				paused: false,
			}
		},
		computed: {
			autoPlayDisabled: function() {
				var userAgent = navigator.userAgent || navigator.vendor || window.opera;
				return userAgent.match(/iPad/i) || 
					userAgent.match(/iPhone/i) ||
					userAgent.match(/iPod/i) || 
					userAgent.match(/Android/i);
			},
			playlistPosition: function () {
				var position = 0;
				if (this.currentItem.slug)
					return position;
				else {
					for (var i = 0; i < this.playlistItems.length; i++) {
						if (this.currentItem.details.id == this.playlistItems[i].id) {
							position = i + 1;
							if (position == this.playlistItems.length) {
								if (this.looping) {
									position = 0;
								} else {
									position = -1;
								}
							}
							break;
						}
					}
				}

				return position;
			},
		},
		created: function() {
			var ytTag = document.createElement('script');
			ytTag.src = "https://www.youtube.com/iframe_api";
			var firstScriptTag = document.getElementsByTagName('script')[0];
			firstScriptTag.parentNode.insertBefore(ytTag, firstScriptTag);

			this.initSetup();

			this.$on('endOfList', function() { 
				this.playlistMessage = "That's it, we've reached the end of your list.";
			});

			window.addEventListener('beforeunload', function(e){
				if (this.playing) {
					this.gaInterrupted(this.currentItem);
				}
			}.bind(this));
		},
		methods: {
			initSetup: function() {

			},
			getItem: function(data) { //data not id
				
				if (this.playing) 
					this.gaInterrupted(this.currentItem);

				$.getJSON(nmu.adminAjax, data)
				.done(function(resp){
					if (resp) {
						this.currentItem = resp.currentItem;

						if (resp.similar) {
							this.similar = resp.similar;
						}
					}
				}.bind(this))
				.fail(function(resp){
					this.playlistMessage = "Item unavailable";
				}.bind(this))
				.always(function(resp){
					this.playlistMessage = '';
				}.bind(this));
			},
			getItemById: function(id) {
				var data = { action: 'list_play', id: id };
				this.getItem(data);
			},
			play: function() { //play button
				if (this.playing && this.paused) {
					this.resume();
				} else if (this.playing && !this.paused) {
					this.pause();						
				} else if (this.playlistPosition > -1){ //not at end of list
					this.getItemById(this.playlistItems[this.playlistPosition].id);
				}
			},
			pause: function() { //pause button
				this.paused = true;
				//this.playing = false;
				if (this.currentItem.service == 'youtube') {
					this.currentItem.widget.pauseVideo();
				} else {
					this.currentItem.widget.pause();
				}
				this.gaPause(this.currentItem);
			},
			resume: function() { //play button
				this.paused = false;
				if (this.currentItem.service == 'youtube') {
					this.currentItem.widget.playVideo();
				} else {
					this.currentItem.widget.play();
				}
				this.gaResume(this.currentItem);
			},
			addToPlaylist: function(id, nonce) {
				$.get(
					nmu.adminAjax,
					{ action: 'add_to_playlist', postID: id, nonce: nonce }
				).done(function(resp){
					if (resp == 'success') {
						this.playlistMessage = "Added to your list!";
						this.gaEvent('Added to Playlist ' + nmu.currentUserID, this.gaTrackLabel());
					}
				}.bind(this)).fail(function(resp){

				}.bind(this));
			},
			highlightSelected: function(item, oldVal) {
				this.playlistItems.forEach((e) => { e.active = false; }); //or selecting a playlist item
				this.playlistItems.forEach(function(e) {
					if (item.details.id == e.id) {
						e.active = true;
					}
				});
			},
			focusPlayingItem: function() {
				if ($('.screen iframe').length) {
					$('.screen iframe').attr('height', '100%').attr('width', '100%');
				}
			},
			advance: function() {
				if (this.playlistPosition == -1) {
					//computed prop returns -1 when looping off and end of list reached
					this.$emit('endOfList');
					return;
				} else {
					this.getItemById(this.playlistItems[this.playlistPosition].id);
				}
			},
			forward: function() {
				this.advance();
				this.gaEvent('Forward Track',this.gaTrackLabel());
			},
			reverse: function() {
				if (this.playlistPosition == 0)
					return;

				this.getItemById(this.playlistItems[this.playlistPosition - 2].id);
				this.gaEvent('Reverse Track',this.gaTrackLabel());
			},
			itemError: function() {
				//this.currentItem.widget = {};
				if (this.playlistPosition > -1){ // not at end of playlist
					this.playlistMessage = "There was a problem with that media item, moving on to the next...";
					this.advance();
				} else {
					this.playlistMessage = "There was a problem with that media item.";
				}
			}
		},
		watch: {
			currentItem: function(item, oldVal) {

				this.highlightSelected(item, oldVal);

				this.$nextTick(function() {

					this.focusPlayingItem();

					var self = this;

					switch (item.service) {
						case 'youtube':
							let ytErrorTriggered = false;
							this.currentItem.widget = new YT.Player('player', {
								events: {
									'onError': (e) => {
										if(!ytErrorTriggered){ //onError sometimes fires twice
											this.currentItem.embed = "<div id='error-wrapper'><p class='embed-error'>I'm sorry, this media seems to be unavailable.</p></div>";
											this.itemError();
											ytErrorTriggered = true;
										}
									},
									'onStateChange': function(e) {
										if (e.data == YT.PlayerState.ENDED) {
											this.playing = false;
											this.gaFinish(Math.ceil(e.target.getCurrentTime()));
											this.advance();
										}
										if (e.data == YT.PlayerState.PLAYING) {
											this.playing = true;
											this.paused = false;
											this.gaStart();
										}
										if (e.data == YT.PlayerState.PAUSED) {
											this.paused = true;
										}
									}.bind(this),
									'onReady': function(e) {
										e.target.playVideo();
									}
								}
							});
							break;
						case 'vimeo':
							this.currentItem.widget = new Vimeo.Player($('iframe#player')[0]);
							
							this.currentItem.widget.on('play', function() {
								this.playing = true;
								this.paused = false;
								this.gaStart();
							}.bind(this));

							this.currentItem.widget.on('pause', function() {
								this.paused = true;
							}.bind(this));

							this.currentItem.widget.on('ended', function(data) {
								this.playing = false;
								this.gaFinish(Math.ceil(data.seconds));
								this.advance();
							}.bind(this));

							this.currentItem.widget.play();
							break;
						case 'soundcloud':
							$('.player iframe').attr('id', 'sc');
							this.currentItem.widget = SC.Widget('sc');
							var sc_sounds = 1;
							var duration = 0;

							this.currentItem.widget.bind(SC.Widget.Events.READY, function(){
								
								this.currentItem.widget.getSounds(function(allSounds){
									sc_sounds = allSounds.length;
									duration = allSounds.reduce(function(sum, s) {
										return sum + (s.duration / 1000);
									}, 0);
								});

								this.currentItem.widget.play();
								//const promise = this.currentItem.widget.play();

							}.bind(this));

							this.currentItem.widget.bind(SC.Widget.Events.PLAY, function() {
								this.playing = true;
								this.paused = false;
								this.gaStart();
							}.bind(this));

							this.currentItem.widget.bind(SC.Widget.Events.PAUSE, function() {
								this.paused = true;
							}.bind(this));

							this.currentItem.widget.bind(SC.Widget.Events.FINISH, function() {
								this.currentItem.widget.getCurrentSoundIndex(function(currentSoundIndex) {
									if ((currentSoundIndex + 1) == sc_sounds) {
										this.playing = false;
										this.gaFinish(Math.ceil(duration));
										this.advance();
									}
								}.bind(this));
							}.bind(this));
							break;
						case 'mp3':
							$('#player').mediaelementplayer({
								setDimensions: false,
								success: (media, node, instance) => { //arrow syntax preserves "this"
									$(node).on("error", (e) => { //error loading file
							            this.currentItem.widget = {};
							            media.remove();
							            this.itemError();
							        });

									media.addEventListener('playing', () => {
										this.playing = true;
										this.paused = false;
										this.gaStart();
									});

									media.addEventListener('pause', () => {
										this.paused = true;
									});

									media.addEventListener('ended', () => {
										this.playing = false;
										let duration = this.currentItem.widget.getCurrentTime();
										this.gaFinish(Math.ceil(duration));
										this.advance();
									});
									this.currentItem.widget = instance;
								}
							});
							break;
						case 'error':
							this.currentItem.widget = {};
							this.itemError();
							break;
						default: //an initial nexttick happens in which item is an observer object, so there's no service., so doing advance here autoplays the first track on load...
							this.currentItem.widget = {};
							//this.playlistMessage = "There was a problem with that media item, moving on to the next...";
							//this.advance();
					}
					//$('.player .meta').show();
					$(this.$refs.meta).show();
				});
			}
		}
	};

jQuery(function($){
	pusher = new Pusher(nmu.pusherKey, { cluster: nmu.pusherCluster, encrypted: true});

	counterstreamApp = new Vue({
		el: "#counterstream",
		data: function() {
			return {
				track: {
					id: 0,
					title: 'title', 
					artist: 'artist', 
					album: 'album',
					performers: 'performers',
					buy: 'buy link',
					endsAt:'00:00:00', 
					coverArt: ''
				},
				tracks: [],
				pusher: {},
				channel: {},
				radioMessage: '',
				playing: false,
				listenedDuration: 0
			};
		},
		created: function() {
			this.getCurrentTrack();
			this.getTracks();
			this.updateBanner();

			this.pusher = pusher;
			this.channel = this.pusher.subscribe('now-playing');
			this.channel.bind('new-track', function(data){
				this.getCurrentTrack();
				this.getTracks();
			}.bind(this));
		},
		methods: {
			getCurrentTrack: function() {
				$.ajax({
					url: 'https://counterstream.newmusicusa.org/current_track',
					dataType: 'jsonp',
					jsonpCallback: 'current'
				}).done(function(resp) {
					if (resp) {
						var track = {
							id: resp.TrackInfo.ID,
							title: resp.TrackInfo.Track,
							artist: resp.TrackInfo.Composer,
							album: resp.TrackInfo.Album,
							performers: resp.TrackInfo.Performers,
							endsAt: resp.ends_at
						};
						if (resp.TrackInfo.CoverArt) {
							track.coverArt = resp.TrackInfo.CoverArt;
						}
						if (resp.TrackInfo.AmazonDetailPage) {
							track.buy = resp.TrackInfo.AmazonDetailPage;
						}
						this.track = track;
					} else {
						this.radioMessage = "Sorry, there doesn't seem to be anything on the air at the moment."
					}
				}.bind(this));
			},
			getTracks: function() {
				$.ajax({
					url: 'https://counterstream.newmusicusa.org/recent_tracks',
					dataType: 'jsonp',
					jsonpCallback: 'recent'
				}).done(function(resp){
					if (resp) {
						this.tracks = resp.Tracks;
					}
				}.bind(this));
			},
			updateBanner: function() {
				let nowPlaying = `Now Playing: ${this.track.title} by ${this.track.artist}`;
				$('.handle.broadcast .description').fadeOut('fast', function() {
					$(this).html(nowPlaying).fadeIn('fast');
				});
			},
			gtagEvent: function() {
				if (typeof gtag != 'undefined') {
					let gtagLabel = `${this.track.artist}: ${this.track.title}`;
					gtag('event', 'Buy on Amazon', {'event_category': 'Counterstream', 'event_label': gtagLabel});
				}
			}
		},
		watch: {
			track: function() {
				this.updateBanner();
			}
		}
	});

	function initPlaylist() {
		playerApp = new Vue({
			el: "#playlist",
			extends: basicPlaylist,
			mixins: [featuredNames, share, gaEvents, modPlaylistFunctions]
		});
	}

	$('.media-handles .handle').click(function(){
		$this = $(this);

		if ($this.hasClass('broadcast')) { 
			if ($this.hasClass('active')) {
				$('#counterstream').slideUp();
				$this.removeClass('active');
				if (typeof gtag != 'undefined') {
					gtag('event', 'Counterstream', {'event_category': 'Header', 'event_label': 'Slide Closed'});
				}
			} else {
				$('#playlist').slideUp();
				$('.media-handles .list').removeClass('active');
				$('#counterstream').slideDown();
				$this.addClass('active');
				if (typeof gtag != 'undefined') {
					gtag('event', 'Counterstream', {'event_category': 'Header', 'event_label': 'Slide Open'});
				}
			}
		} else if ($this.hasClass('list')) { //media on demand tab
			if ($this.hasClass('active')) {
				$('#playlist').slideUp();
				$this.removeClass('active');
				if (typeof gtag != 'undefined') {
					gtag('event', 'Media On Demand', {'event_category': 'Header', 'event_label': 'Slide Closed'});
				}
			} else {
				$('#counterstream').slideUp();
				$('.media-handles .broadcast').removeClass('active');
				$('#playlist').slideDown('fast', function() {
					if (typeof playerApp === 'undefined')
						initPlaylist();
				});
				$this.addClass('active');
				if (typeof gtag != 'undefined') {
					gtag('event', 'Media On Demand', {'event_category': 'Header', 'event_label': 'Slide Open'});
				}
			}
		}
	});

	$('a.is-playlist-owner').click(function(e){
		e.preventDefault();
		$('html, body').animate({ scrollTop: 0 }, "slow");
		
		$('section.media-experience #playlist').slideDown('fast', function() {
			initPlaylist();
			playerApp.toggled = 'my-playlist';
		});
		
		$('section.media-experience .handle.list').addClass('active');
	});

	$('#cs_stream').mediaelementplayer({
		features:['playpause', 'volume'],
		setDimensions: false,
		success: function(mediaElement, originalNode) {
			mediaElement.addEventListener('playing', function() {
				counterstreamApp.playing = true;
				if (typeof gtag != 'undefined') {
					gtag('event', 'cs_play', {event_category: 'Counterstream', event_action: 'Click', event_label: 'Play'});
				}
			});

			mediaElement.addEventListener('pause', function() {
				let duration = Math.ceil(mejs.players["mep_0"].getCurrentTime());
				counterstreamApp.listenedDuration += duration;
				counterstreamApp.playing = false;
			});
		}
	});

	window.addEventListener('beforeunload', function(e){
		let duration = 0;

		if (counterstreamApp.playing) {
			duration = Math.ceil(mejs.players["mep_0"].getCurrentTime());
		}

		duration += counterstreamApp.listenedDuration;
		
		if (duration && typeof gtag !== 'undefined') {
			gtag('event', 'cs_stop', {event_category: 'Counterstream', event_action: 'Click', event_label: 'Stop', value: duration});
		}
	});

	$('#playlist .stream-box a').click(function(e){ e.preventDefault(); });


	$('.section-link a#play-counterstream').click(function(e){
		e.preventDefault();
		triggerOpenCS();
	});

});