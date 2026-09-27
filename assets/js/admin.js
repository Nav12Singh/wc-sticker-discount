/**
 * WooCommerce > Sticker Discount admin page.
 *
 * - Category field: AJAX search, like the Upsells / Cross-sells fields on the product screen.
 * - Live preview: only reads the settings form; it never changes what is submitted.
 */
( function ( $ ) {
	'use strict';

	var data = window.wcsdAdmin;
	var $preview = $( '.wcsd-preview' );

	if ( ! data ) {
		return;
	}

	$( '.wcsd-category-search' ).filter( ':not(.enhanced)' ).each( function () {
		var $select = $( this );

		$select.selectWoo( {
			placeholder: $select.data( 'placeholder' ),
			minimumInputLength: parseInt( $select.data( 'minimum_input_length' ), 10 ) || 3,
			// Results show the parent path and product count; chosen chips show only the category name.
			templateSelection: function ( item ) {
				return item.label || item.text;
			},
			language: {
				inputTooShort: function ( args ) {
					var remaining = args.minimum - args.input.length;

					return 1 === remaining ? data.i18n.typeOne : data.i18n.typeMore.replace( '%s', remaining );
				},
				searching: function () {
					return data.i18n.searching;
				},
				noResults: function () {
					return data.i18n.noMatches;
				},
				errorLoading: function () {
					return data.i18n.loadError;
				}
			},
			ajax: {
				url: data.ajaxUrl,
				dataType: 'json',
				delay: 250,
				cache: true,
				data: function ( params ) {
					return {
						term: params.term,
						action: 'wcsd_search_categories',
						security: data.searchNonce
					};
				},
				processResults: function ( results ) {
					return { results: Array.isArray( results ) ? results : [] };
				}
			}
		} ).addClass( 'enhanced' );

		// The real <select> is hidden behind the search box, so show the "required" message ourselves.
		var $container = $select.next( '.select2-container' );
		var $error = $( '<p class="wcsd-field-error" role="alert"></p>' ).text( data.i18n.categoryRequired ).hide().insertAfter( $container );

		$select.on( 'invalid', function ( event ) {
			event.preventDefault();
			$container.addClass( 'wcsd-has-error' );
			$error.show();
			$container[ 0 ].scrollIntoView( { block: 'center' } );
		} );

		$select.on( 'change', function () {
			if ( ( $select.val() || [] ).length ) {
				$container.removeClass( 'wcsd-has-error' );
				$error.hide();
			}
		} );
	} );

	if ( ! $preview.length ) {
		return;
	}

	var $fields = {
		enabled: $( '#wcsd_enabled' ),
		label: $( '#wcsd_fee_label' ),
		minQty: $( '#wcsd_min_qty' ),
		type: $( '#wcsd_discount_type' ),
		value: $( '#wcsd_discount_value' )
	};
	var $sheet = $preview.find( '[data-wcsd-sheet]' );
	var maxStickers = 99;
	var count = null;
	var countTouched = false;
	var wasUnlocked = null;

	function format( template ) {
		var args = arguments;
		var next = 1;

		return template.replace( /%(\d)\$s|%s/g, function ( match, position ) {
			return String( position ? args[ position ] : args[ next++ ] );
		} );
	}

	function round( amount ) {
		var factor = Math.pow( 10, data.currency.decimals );

		return Math.round( amount * factor ) / factor;
	}

	function formatNumber( number ) {
		return String( number ).replace( '.', data.currency.decimalSep );
	}

	function formatPrice( amount ) {
		var parts = Math.abs( amount ).toFixed( data.currency.decimals ).split( '.' );
		var whole = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, data.currency.thousandSep );
		var number = parts[ 1 ] ? whole + data.currency.decimalSep + parts[ 1 ] : whole;

		return data.currency.format.replace( '%1$s', data.currency.symbol ).replace( '%2$s', number );
	}

	// Mirrors WCSD_Settings: minimum quantity is at least 1, a percentage is capped at 100.
	function readRule() {
		var type = 'fixed' === $fields.type.val() ? 'fixed' : 'percent';
		var value = parseFloat( String( $fields.value.val() ).replace( data.currency.decimalSep, '.' ) );
		var label = $.trim( $fields.label.val() ) || data.defaultLabel;

		value = Math.max( 0, isNaN( value ) ? 0 : value );

		if ( 'percent' === type ) {
			value = Math.min( 100, value );
			label += ' (' + formatNumber( value ) + '%)';
		}

		return {
			enabled: $fields.enabled.is( ':checked' ),
			minQty: Math.max( 1, parseInt( $fields.minQty.val(), 10 ) || 1 ),
			type: type,
			value: value,
			label: label
		};
	}

	function renderSheet( stickers, slots ) {
		var items = [];

		for ( var i = 0; i < slots; i++ ) {
			items.push( '<li class="' + ( i < stickers ? 'wcsd-sticker' : 'wcsd-slot' ) + '"></li>' );
		}

		$sheet.html( items.join( '' ) );
	}

	function render() {
		var rule = readRule();

		if ( ! countTouched || null === count ) {
			count = rule.minQty;
		}

		count = Math.min( maxStickers, Math.max( 1, count ) );

		var subtotal = round( count * data.samplePrice );
		var qualifies = count >= rule.minQty;
		var discount = 0;

		if ( rule.enabled && qualifies ) {
			discount = 'percent' === rule.type ? subtotal * rule.value / 100 : Math.min( rule.value, subtotal );
			discount = round( discount );
		}

		$( '[data-wcsd-status]' )
			.text( rule.enabled ? data.i18n.active : data.i18n.off )
			.toggleClass( 'is-off', ! rule.enabled );

		$preview.find( '[data-wcsd-count]' ).text( format( data.i18n.inCart, count ) );
		$preview.find( '[data-wcsd-step="-1"]' ).prop( 'disabled', count <= 1 );
		$preview.find( '[data-wcsd-step="1"]' ).prop( 'disabled', count >= maxStickers );

		renderSheet( count, Math.min( 40, Math.max( count, rule.minQty ) ) );

		var status = data.i18n.applied;

		if ( ! rule.enabled ) {
			status = data.i18n.disabled;
		} else if ( ! qualifies ) {
			status = format( data.i18n.needMore, rule.minQty - count );
		}

		$preview.find( '[data-wcsd-progress]' ).text( status ).toggleClass( 'is-applied', discount > 0 );
		$preview.find( '[data-wcsd-subtotal]' ).text( formatPrice( subtotal ) );
		$preview.find( '[data-wcsd-label]' ).text( rule.label );
		$preview.find( '[data-wcsd-discount]' ).text( '-' + formatPrice( discount ) );
		$preview.find( '[data-wcsd-discount-row]' ).prop( 'hidden', discount <= 0 );
		$preview.find( '[data-wcsd-total]' ).text( formatPrice( subtotal - discount ) );

		var unlocked = discount > 0;

		if ( unlocked && false === wasUnlocked ) {
			$sheet.removeClass( 'is-unlocking' );
			$sheet[ 0 ].offsetWidth; // Restart the animation.
			$sheet.addClass( 'is-unlocking' );
		}

		wasUnlocked = unlocked;
	}

	$preview.on( 'click', '[data-wcsd-step]', function () {
		countTouched = true;
		count += parseInt( $( this ).data( 'wcsd-step' ), 10 );
		render();
	} );

	$sheet.on( 'animationend', function () {
		$sheet.removeClass( 'is-unlocking' );
	} );

	// jQuery listener also catches the change events fired by the enhanced (select2) selects.
	$( document ).on( 'input change', '#wcsd_enabled, #wcsd_fee_label, #wcsd_min_qty, #wcsd_discount_type, #wcsd_discount_value', render );

	$preview.prop( 'hidden', false );
	render();
}( jQuery ) );
