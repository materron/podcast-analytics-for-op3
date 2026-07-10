/* Podcast Analytics for OP3 — Admin JS v2.0 */
/* global op3paData, jQuery */

( function ( $ ) {
	'use strict';

	// ── Stats page ──────────────────────────────────────────────────────────

	var customRange = null; // { start: 'Y-m-d', end: 'Y-m-d' } when a custom range is applied, else null.

	$( document ).on( 'click', '.op3pa-period-btn', function () {
		var $btn = $( this );
		$( '.op3pa-period-btn' ).removeClass( 'active' );
		$btn.addClass( 'active' );
		customRange = null;
		$( '.op3pa-date-range' ).removeClass( 'active' );
		loadStats();
	} );

	$( '#op3pa-range-apply' ).on( 'click', function () {
		var start = $( '#op3pa-range-start' ).val();
		var end   = $( '#op3pa-range-end' ).val();

		if ( ! start ) {
			return;
		}

		customRange = { start: start, end: end || '' };
		$( '.op3pa-period-btn' ).removeClass( 'active' );
		$( '.op3pa-date-range' ).addClass( 'active' );
		loadStats();
	} );

	$( '#op3pa-refresh' ).on( 'click', function () {
		loadStats( true );
	} );

	$( document ).on( 'change', '.op3pa-podcast-check', function () {
		// If "network" checkbox toggled, sync all others.
		if ( $( this ).val() === 'network' ) {
			var checked = $( this ).is( ':checked' );
			$( '.op3pa-podcast-check[value!="network"]' ).prop( 'checked', checked );
		}
		loadStats();
	} );

	function getSelectedIndexes() {
		var indexes = [];
		$( '.op3pa-podcast-check:checked' ).each( function () {
			indexes.push( $( this ).val() );
		} );
		return indexes;
	}

	var currentStatsRequest = null;

	function loadStats( forceRefresh ) {
		var indexes = getSelectedIndexes();
		var payload = {
			action:  'op3pa_refresh_stats',
			nonce:   op3paData.nonce,
			indexes: indexes,
		};

		if ( customRange ) {
			payload.start = customRange.start;
			payload.end   = customRange.end;
		} else {
			payload.days = parseInt( $( '.op3pa-period-btn.active' ).data( 'days' ) || 1, 10 );
		}

		// Abort any in-flight request so a slow earlier response can't overwrite
		// the result of a newer selection (out-of-order AJAX race condition).
		if ( currentStatsRequest ) {
			currentStatsRequest.abort();
		}

		$( '#op3pa-stats-container' ).html(
			'<div class="op3pa-loading">' + op3paData.strings.refreshing + '</div>'
		);

		currentStatsRequest = $.ajax( {
			url:    op3paData.ajaxUrl,
			method: 'POST',
			data:   payload,
			success: function ( response ) {
				if ( response.success ) {
					$( '#op3pa-stats-container' ).html( response.data.html );
				} else {
					$( '#op3pa-stats-container' ).html(
						'<p class="op3pa-error">' + op3paData.strings.error + '</p>'
					);
				}
			},
			error: function ( jqXHR, textStatus ) {
				if ( 'abort' === textStatus ) {
					return; // Superseded by a newer request; nothing to show.
				}
				$( '#op3pa-stats-container' ).html(
					'<p class="op3pa-error">' + op3paData.strings.error + '</p>'
				);
			},
			complete: function ( jqXHR ) {
				if ( currentStatsRequest === jqXHR ) {
					currentStatsRequest = null;
				}
			},
		} );
	}

	// ── Country map tooltip ─────────────────────────────────────────────────

	var $mapTooltip = $( '<div class="op3pa-map-tooltip"></div>' ).appendTo( 'body' ).hide();

	$( document ).on( 'mouseenter', '.op3pa-country-map [data-op3pa-country]', function () {
		var $el = $( this );
		$mapTooltip
			.html(
				'<strong>' + $el.attr( 'data-op3pa-country' ) + '</strong>: ' +
				$el.attr( 'data-op3pa-downloads' )
			)
			.show();
	} );

	$( document ).on( 'mousemove', '.op3pa-country-map [data-op3pa-country]', function ( e ) {
		$mapTooltip.css( { top: e.pageY + 14, left: e.pageX + 14 } );
	} );

	$( document ).on( 'mouseleave', '.op3pa-country-map [data-op3pa-country]', function () {
		$mapTooltip.hide();
	} );

	// ── Generic floating tooltip (time-of-day / weekday bar charts, etc.) ────

	$( document ).on( 'mouseenter', '[data-op3pa-tooltip]', function () {
		$mapTooltip.html( $( this ).attr( 'data-op3pa-tooltip' ) ).show();
	} );

	$( document ).on( 'mousemove', '[data-op3pa-tooltip]', function ( e ) {
		$mapTooltip.css( { top: e.pageY + 14, left: e.pageX + 14 } );
	} );

	$( document ).on( 'mouseleave', '[data-op3pa-tooltip]', function () {
		$mapTooltip.hide();
	} );

	// ── Show more / show less episode rows ────────────────────────────────

	$( document ).on( 'click', '.op3pa-show-more-btn', function () {
		var $btn   = $( this );
		var $table = $btn.closest( 'table' );
		$table.find( '.op3pa-row-extra' ).show();
		$btn.closest( '.op3pa-show-more-row' ).remove();
	} );

	// ── Dashboard widget pagination ─────────────────────────────────────────

	$( document ).on( 'click', '.op3pa-nav-next, .op3pa-nav-prev', function () {
		var $btn     = $( this );
		var $widget  = $btn.closest( '.op3pa-widget' );
		var $slides  = $widget.find( '.op3pa-widget-slides' );
		var $items   = $slides.find( '.op3pa-widget-slide' );
		var total    = $items.length;
		if ( total < 2 ) return;
		var current  = parseInt( $slides.data( 'current' ) || 0, 10 );
		var isNext   = $btn.hasClass( 'op3pa-nav-next' );

		$items.eq( current ).hide();
		current = isNext
			? ( current + 1 ) % total
			: ( current - 1 + total ) % total;
		$items.eq( current ).show();
		$slides.data( 'current', current );

		$widget.find( '.op3pa-nav-dot' ).removeClass( 'active' ).eq( current ).addClass( 'active' );
	} );

} )( jQuery );
