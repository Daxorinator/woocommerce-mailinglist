/* global jQuery, MLP, ajaxurl, tinymce, wp */
jQuery( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.mlp-confirm', function ( e ) {
		if ( ! window.confirm( $( this ).data( 'confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	/* ---- List editor ---------------------------------------------------- */

	var $listType = $( 'input[name="type"]' );

	if ( $listType.length ) {
		var toggleMode = function () {
			var type = $listType.filter( ':checked' ).val();
			var mode = $( '#mlp_wc_mode' ).val();

			$( '.mlp-source-marketing' ).toggle( type === 'marketing' );
			$( '.mlp-source-product' ).toggle( type === 'product' );

			$( '.mlp-wc-row' ).each( function () {
				var modes = String( $( this ).data( 'modes' ) ).split( ' ' );
				$( this ).toggle( type === 'product' && modes.indexOf( mode ) !== -1 );
			} );
		};

		$listType.on( 'change', toggleMode );
		$( '#mlp_wc_mode' ).on( 'change', toggleMode );
		toggleMode();

		$( '#mlp_form_id' ).on( 'change', function () {
			var $email = $( '#mlp_email_field' );
			var $name = $( '#mlp_name_field' );

			$.post( ajaxurl, { action: 'mlp_form_fields', _ajax_nonce: MLP.nonce, form_id: $( this ).val() }, function ( response ) {
				if ( ! response || ! response.success ) {
					return;
				}
				$email.empty().append( $( '<option>' ).val( '' ).text( MLP.i18n.autoDetect ) );
				$name.empty().append( $( '<option>' ).val( '' ).text( MLP.i18n.none ) );

				$.each( response.data, function ( i, field ) {
					var $option = $( '<option>' ).val( field.id ).text( field.label );
					if ( field.type !== 'name' ) {
						$email.append( $option.clone() );
					}
					if ( field.type !== 'email' ) {
						$name.append( $option.clone() );
					}
				} );
			} );
		} );
	}

	/* ---- Composer -------------------------------------------------------- */

	var $form = $( '#mlp-compose-form' );

	if ( ! $form.length ) {
		return;
	}

	var $emailType = $( '#mlp_email_type' );
	var $list = $( '#mlp_list_id' );
	var $status = $( '#mlp-ajax-status' );
	var codeMirror = null;

	// The email type selects which lists can be chosen: marketing → WPForms lists, product → WooCommerce lists.
	function fillLists() {
		var type = $emailType.val();
		var current = String( $list.val() || $list.data( 'current' ) || '' );

		$list.empty();
		$.each( MLP.lists, function ( i, list ) {
			if ( list.type === type ) {
				$list.append( $( '<option>' ).val( list.id ).text( list.name + ' (' + list.count + ')' ).data( 'list', list ) );
			}
		} );
		if ( ! $list.children().length ) {
			$list.append( $( '<option>' ).val( '0' ).text( MLP.i18n.noLists ) );
		} else if ( $list.find( 'option[value="' + current + '"]' ).length ) {
			$list.val( current );
		}

		$( '#mlp-type-hint' ).text( type === 'product' ? MLP.i18n.hintProduct : MLP.i18n.hintMarketing );
	}

	$emailType.on( 'change', fillLists );
	fillLists();

	function format() {
		return $form.find( 'input[name="body_format"]:checked' ).val() === 'html' ? 'html' : 'visual';
	}

	function visualEditor() {
		var editor = window.tinymce ? tinymce.get( 'mlp_body_visual' ) : null;
		return editor && ! editor.isHidden() ? editor : null;
	}

	function getVisual() {
		var editor = visualEditor();
		return editor ? editor.getContent() : $( '#mlp_body_visual' ).val();
	}

	function getHtml() {
		return codeMirror ? codeMirror.getValue() : $( '#mlp_body_html' ).val();
	}

	function setHtml( value ) {
		if ( codeMirror ) {
			codeMirror.setValue( value );
		} else {
			$( '#mlp_body_html' ).val( value );
		}
	}

	function toggleFormat() {
		var isHtml = format() === 'html';

		// Carry the content across the first time, so switching modes does not lose work.
		if ( isHtml && $.trim( getHtml() ) === '' ) {
			setHtml( getVisual() );
		}

		$( '#mlp-editor-visual' ).toggle( ! isHtml );
		$( '#mlp-editor-html' ).toggle( isHtml );

		// CodeMirror must be created (or refreshed) while visible to size itself correctly.
		if ( isHtml && MLP.codeEditor && wp.codeEditor ) {
			if ( ! codeMirror ) {
				codeMirror = wp.codeEditor.initialize( 'mlp_body_html', MLP.codeEditor ).codemirror;
			} else {
				codeMirror.refresh();
			}
		}
	}

	$form.find( 'input[name="body_format"]' ).on( 'change', toggleFormat );
	toggleFormat();

	function payload( action ) {
		return {
			action: action,
			_ajax_nonce: MLP.nonce,
			list_id: $list.val(),
			email_type: $emailType.val(),
			subject: $( '#mlp_subject' ).val(),
			body_format: format(),
			mlp_body_visual: getVisual(),
			mlp_body_html: getHtml()
		};
	}

	$( '#mlp-preview-btn' ).on( 'click', function () {
		$status.text( MLP.i18n.working );
		$.post( ajaxurl, payload( 'mlp_preview' ) )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					$status.text( MLP.i18n.error );
					return;
				}
				$status.text( '' );
				$( '#mlp-preview-subject' ).text( response.data.subject );
				$( '#mlp-preview-frame' ).attr( 'srcdoc', response.data.html );
				$( '#mlp-preview' ).show().get( 0 ).scrollIntoView( { behavior: 'smooth' } );
			} )
			.fail( function () {
				$status.text( MLP.i18n.error );
			} );
	} );

	$( '#mlp-send-test' ).on( 'click', function () {
		var data = payload( 'mlp_send_test' );
		data.to = $( '#mlp_test_to' ).val();

		$status.text( MLP.i18n.working );
		$.post( ajaxurl, data )
			.done( function ( response ) {
				$status.text( response && typeof response.data === 'string' ? response.data : MLP.i18n.error );
			} )
			.fail( function () {
				$status.text( MLP.i18n.error );
			} );
	} );

	$( '#mlp-send-btn' ).on( 'click', function ( e ) {
		var list = $list.find( 'option:selected' ).data( 'list' );
		var message;

		if ( ! list ) {
			e.preventDefault();
			window.alert( MLP.i18n.noLists );
			return;
		}
		message = MLP.i18n.confirmSend.replace( '%1$s', list.count ).replace( '%2$s', list.name );
		if ( ! window.confirm( message ) ) {
			e.preventDefault();
		}
	} );
} );
