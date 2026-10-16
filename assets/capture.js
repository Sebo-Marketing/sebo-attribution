/**
 * Sebo Attribution — records where a visitor came from.
 *
 * Runs on every page. When a page view carries attribution signals (UTM tags,
 * an ad click ID, or an external referrer) it records a "touch" in a
 * first-party cookie. Nothing is sent anywhere: the cookie is read by the
 * server when a Gravity Form is submitted.
 *
 *   first  — the first touch we saw. Never overwritten.
 *   last   — the most recent touch that was not Direct. A visitor who clicks
 *            an ad and later comes back by typing the address keeps the ad
 *            as their last touch, which matches how GA4 and Google Ads credit
 *            conversions ("last non-direct click").
 *   ids    — the latest value of each ad click ID, kept separately so a later
 *            organic visit does not lose the GCLID that offline conversion
 *            import needs.
 *
 * This file must stay dependency-free: it loads on cached pages for every
 * visitor, and jQuery is not guaranteed on a block theme.
 */
( function () {
	'use strict';

	var cfg = window.seboAttribution || {};
	var COOKIE = cfg.cookie || 'sebo_attr';
	var DAYS = parseInt( cfg.days, 10 ) || 90;
	var MAX_URL = 200;

	var CLICK_IDS = [ 'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid' ];
	var GOOGLE_IDS = [ 'gclid', 'gbraid', 'wbraid' ];

	// [ pattern matched against a hostname, source name ]
	var SEARCH = [
		[ /(^|\.)google\.[a-z.]+$/, 'google' ],
		[ /(^|\.)bing\.com$/, 'bing' ],
		[ /(^|\.)yahoo\.[a-z.]+$/, 'yahoo' ],
		[ /(^|\.)duckduckgo\.com$/, 'duckduckgo' ],
		[ /(^|\.)ecosia\.org$/, 'ecosia' ],
		[ /(^|\.)baidu\.com$/, 'baidu' ],
		[ /(^|\.)yandex\.[a-z.]+$/, 'yandex' ],
		[ /(^|\.)search\.brave\.com$/, 'brave' ],
		[ /(^|\.)startpage\.com$/, 'startpage' ],
		[ /(^|\.)qwant\.com$/, 'qwant' ],
		[ /(^|\.)aol\.com$/, 'aol' ],
		[ /(^|\.)ask\.com$/, 'ask' ],
		[ /(^|\.)naver\.com$/, 'naver' ],
		[ /(^|\.)chatgpt\.com$/, 'chatgpt' ],
		[ /(^|\.)perplexity\.ai$/, 'perplexity' ]
	];
	var SOCIAL = [
		[ /(^|\.)(facebook\.com|fb\.com|fb\.me)$/, 'facebook' ],
		[ /(^|\.)instagram\.com$/, 'instagram' ],
		[ /(^|\.)(linkedin\.com|lnkd\.in)$/, 'linkedin' ],
		[ /(^|\.)(twitter\.com|x\.com|t\.co)$/, 'x' ],
		[ /(^|\.)(youtube\.com|youtu\.be)$/, 'youtube' ],
		[ /(^|\.)tiktok\.com$/, 'tiktok' ],
		[ /(^|\.)pinterest\.[a-z.]+$/, 'pinterest' ],
		[ /(^|\.)reddit\.com$/, 'reddit' ],
		[ /(^|\.)threads\.(net|com)$/, 'threads' ],
		[ /(^|\.)nextdoor\.com$/, 'nextdoor' ],
		[ /(^|\.)snapchat\.com$/, 'snapchat' ],
		[ /(^|\.)quora\.com$/, 'quora' ],
		[ /(^|\.)tumblr\.com$/, 'tumblr' ],
		[ /(^|\.)bsky\.app$/, 'bluesky' ]
	];

	var SEARCH_NAMES = names( SEARCH );
	var SOCIAL_NAMES = names( SOCIAL ).concat( [ 'fb', 'ig', 'twitter', 'meta' ] );

	function names( list ) {
		var out = [];
		for ( var i = 0; i < list.length; i++ ) {
			out.push( list[ i ][ 1 ] );
		}
		return out;
	}

	function lookup( host, list ) {
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ][ 0 ].test( host ) ) {
				return list[ i ][ 1 ];
			}
		}
		return '';
	}

	function bareHost( host ) {
		return String( host || '' ).toLowerCase().replace( /^www\./, '' );
	}

	function clip( value, max ) {
		value = String( value || '' );
		return value.length > max ? value.slice( 0, max ) : value;
	}

	/**
	 * Query parameters, with keys lower-cased. Some ad platforms and hand-built
	 * links send UTM_Source and similar; GA treats those the same.
	 */
	function params() {
		var out = {};
		var search = window.location.search.replace( /^\?/, '' );
		if ( ! search ) {
			return out;
		}
		search.split( '&' ).forEach( function ( pair ) {
			var i = pair.indexOf( '=' );
			var key = i === -1 ? pair : pair.slice( 0, i );
			var value = i === -1 ? '' : pair.slice( i + 1 );
			try {
				key = decodeURIComponent( key.replace( /\+/g, ' ' ) ).toLowerCase();
				value = decodeURIComponent( value.replace( /\+/g, ' ' ) );
			} catch ( e ) {
				return;
			}
			if ( key && ! ( key in out ) ) {
				out[ key ] = clip( value.trim(), 150 );
			}
		} );
		return out;
	}

	/**
	 * Channel grouping, modeled on GA4's default channel groups so the labels
	 * match what the team sees in Analytics.
	 */
	function channel( source, medium, isGoogleOrMicrosoftClick ) {
		var s = String( source || '' ).toLowerCase();
		var m = String( medium || '' ).toLowerCase();
		var isSearch = SEARCH_NAMES.indexOf( s ) !== -1;
		var isSocial = SOCIAL_NAMES.indexOf( s ) !== -1;

		if ( s === '(direct)' && ( m === '(none)' || m === '(not set)' ) ) {
			return 'Direct';
		}
		if ( /^(.*cp.*|ppc|retargeting|paid.*)$/.test( m ) ) {
			if ( isSearch || isGoogleOrMicrosoftClick ) {
				return 'Paid Search';
			}
			if ( isSocial ) {
				return 'Paid Social';
			}
			return 'Paid Other';
		}
		if ( /^(display|banner|expandable|interstitial|cpm)$/.test( m ) ) {
			return 'Display';
		}
		if ( /e[-_ ]?mail/.test( s ) || /e[-_ ]?mail/.test( m ) ) {
			return 'Email';
		}
		if ( m === 'affiliate' ) {
			return 'Affiliates';
		}
		if ( isSocial || /^(social|social[-_ ]network|social[-_ ]media|sm)$/.test( m ) ) {
			return 'Organic Social';
		}
		if ( isSearch || m === 'organic' ) {
			return 'Organic Search';
		}
		if ( m === 'referral' ) {
			return 'Referral';
		}
		return 'Other Campaign';
	}

	/**
	 * Build a touch from the current page view, or return null when this view
	 * carries no attribution signal (an internal page-to-page click).
	 */
	function currentTouch( p ) {
		var here = bareHost( window.location.hostname );
		var refHost = '';
		var referrer = '';
		try {
			if ( document.referrer ) {
				refHost = bareHost( new URL( document.referrer ).hostname );
				referrer = document.referrer;
			}
		} catch ( e ) {}
		var external = refHost && refHost !== here;

		var hasUtm = !! ( p.utm_source || p.utm_medium || p.utm_campaign );
		var googleClick = GOOGLE_IDS.some( function ( k ) { return !! p[ k ]; } );
		var microsoftClick = !! p.msclkid;
		var refSearch = external ? lookup( refHost, SEARCH ) : '';
		var refSocial = external ? lookup( refHost, SOCIAL ) : '';

		var source = '';
		var medium = '';

		if ( hasUtm ) {
			source = p.utm_source || ( googleClick ? 'google' : microsoftClick ? 'bing' : refSearch || refSocial || refHost || '(not set)' );
			medium = p.utm_medium || ( googleClick || microsoftClick ? 'cpc' : '(not set)' );
		} else if ( googleClick ) {
			// Google Ads auto-tagging adds a gclid but no UTM tags.
			source = 'google';
			medium = 'cpc';
		} else if ( microsoftClick ) {
			source = 'bing';
			medium = 'cpc';
		} else if ( external ) {
			source = refSearch || refSocial || refHost;
			medium = refSearch ? 'organic' : refSocial ? 'social' : 'referral';
		} else if ( p.fbclid ) {
			// Facebook adds fbclid to organic link clicks too, and its in-app
			// browser often drops the referrer. Without a paid medium this is
			// organic social, not an ad.
			source = 'facebook';
			medium = 'social';
		} else if ( ! refHost ) {
			source = '(direct)';
			medium = '(none)';
		} else {
			return null;
		}

		return {
			t: Math.floor( Date.now() / 1000 ),
			channel: channel( source, medium, googleClick || microsoftClick ),
			source: source,
			medium: medium,
			campaign: p.utm_campaign || '',
			term: p.utm_term || '',
			content: p.utm_content || '',
			landing: clip( window.location.href, MAX_URL ),
			referrer: clip( external ? referrer : '', MAX_URL )
		};
	}

	function read() {
		var match = document.cookie.match( new RegExp( '(?:^|; )' + COOKIE + '=([^;]*)' ) );
		if ( ! match ) {
			return null;
		}
		try {
			var data = JSON.parse( decodeURIComponent( match[ 1 ] ) );
			return data && typeof data === 'object' ? data : null;
		} catch ( e ) {
			return null;
		}
	}

	function write( data ) {
		var value = encodeURIComponent( JSON.stringify( data ) );
		var secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = COOKIE + '=' + value + '; path=/; max-age=' + ( DAYS * 86400 ) + '; SameSite=Lax' + secure;
	}

	/**
	 * Safari caps cookies written by JavaScript at 7 days. Asking the server to
	 * re-issue the same cookie in an HTTP response gives it the full lifetime.
	 * POST requests are never served from Kinsta's page cache.
	 */
	function refresh() {
		if ( ! cfg.refreshUrl || ! window.fetch ) {
			return;
		}
		try {
			window.fetch( cfg.refreshUrl, { method: 'POST', credentials: 'same-origin', keepalive: true } ).catch( function () {} );
		} catch ( e ) {}
	}

	function record() {
		var p = params();
		var touch = currentTouch( p );
		var data = read() || {};
		var changed = false;

		data.v = 1;
		data.ids = data.ids || {};

		if ( touch ) {
			if ( ! data.first ) {
				data.first = touch;
				changed = true;
			}
			if ( touch.channel !== 'Direct' || ! data.last ) {
				data.last = touch;
				changed = true;
			}
		}

		CLICK_IDS.forEach( function ( key ) {
			if ( p[ key ] && ( ! data.ids[ key ] || data.ids[ key ].v !== p[ key ] ) ) {
				data.ids[ key ] = { v: p[ key ], t: Math.floor( Date.now() / 1000 ) };
				changed = true;
			}
		} );

		if ( changed ) {
			write( data );
			refresh();
		}
	}

	/**
	 * Consent. When the site requires it, nothing is written until the
	 * visitor allows marketing cookies, through the WP Consent API (used by
	 * Complianz, CookieYes, Cookiebot's WP plugin and others) or a custom
	 * signal: set window.seboAttributionConsent = true, or dispatch a
	 * "sebo-attribution-consent" event on document.
	 */
	function consented() {
		if ( window.seboAttributionConsent === true ) {
			return true;
		}
		if ( typeof window.wp_has_consent === 'function' ) {
			try {
				return !! window.wp_has_consent( 'marketing' );
			} catch ( e ) {}
		}
		return false;
	}

	var done = false;
	function run() {
		if ( done ) {
			return;
		}
		done = true;
		record();
	}

	// Exposed for the test suite only; not a public API.
	window.seboAttributionInternals = { channel: channel, currentTouch: currentTouch, params: params, record: record };

	if ( ! cfg.requireConsent || consented() ) {
		run();
		return;
	}
	document.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		if ( e && e.detail && e.detail.marketing === 'allow' ) {
			run();
		}
	} );
	document.addEventListener( 'sebo-attribution-consent', run );
} )();
