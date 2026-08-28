/**
 * Marché Chemin-du-Roy — front-end behaviour.
 *
 * Vanilla JS, no build step. WooCommerce still ships jQuery for its own
 * scripts; where a jQuery event is the documented integration point for other
 * plugins (added_to_cart, removed_from_cart) it is dispatched too, so payment,
 * analytics and upsell plugins keep hearing what they expect.
 *
 * Progressive enhancement throughout: every control here has a working
 * no-JavaScript path — the cart icon is a link to the cart page, filters are a
 * GET form, and add-to-cart buttons fall back to a normal product-page visit.
 */
( function () {
	'use strict';

	var data = window.mcrData || {};
	var $ = function ( sel, root ) { return ( root || document ).querySelector( sel ); };
	var $$ = function ( sel, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) );
	};

	/* ------------------------------------------------------------------ *
	 * Announcements
	 * ------------------------------------------------------------------ */

	function announce( message ) {
		var region = $( '[data-cart-announce]' );
		if ( ! region ) {
			return;
		}
		// Clearing first forces screen readers to re-read an identical message.
		region.textContent = '';
		window.setTimeout( function () {
			region.textContent = message;
		}, 60 );
	}

	/* ------------------------------------------------------------------ *
	 * Cart drawer
	 * ------------------------------------------------------------------ */

	var drawer = {
		sheet: null,
		scrim: null,
		lastFocus: null,

		init: function () {
			this.sheet = $( '[data-cart-sheet]' );
			this.scrim = $( '[data-cart-scrim]' );

			if ( ! this.sheet ) {
				return;
			}

			var self = this;

			document.addEventListener( 'click', function ( event ) {
				var opener = event.target.closest( '[data-cart-open]' );
				if ( opener ) {
					// Ctrl/cmd-click and middle-click should still open the cart page.
					if ( event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button ) {
						return;
					}
					event.preventDefault();
					self.open( opener );
					return;
				}

				if ( event.target.closest( '[data-cart-close]' ) || event.target === self.scrim ) {
					event.preventDefault();
					self.close();
				}
			} );

			document.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && self.isOpen() ) {
					self.close();
				}
				if ( 'Tab' === event.key && self.isOpen() ) {
					self.trapFocus( event );
				}
			} );
		},

		isOpen: function () {
			return this.sheet && ! this.sheet.hidden;
		},

		open: function ( trigger ) {
			if ( ! this.sheet ) {
				return;
			}

			this.lastFocus = trigger || document.activeElement;

			this.sheet.hidden = false;
			if ( this.scrim ) {
				this.scrim.hidden = false;
			}

			// Force a reflow so the transform transition actually runs.
			void this.sheet.offsetWidth;

			this.sheet.classList.add( 'open' );
			if ( this.scrim ) {
				this.scrim.classList.add( 'open' );
			}

			document.body.style.overflow = 'hidden';
			$$( '[data-cart-open]' ).forEach( function ( el ) {
				el.setAttribute( 'aria-expanded', 'true' );
			} );

			var focusable = this.focusable();
			if ( focusable.length ) {
				focusable[ 0 ].focus();
			}
		},

		close: function () {
			if ( ! this.sheet || ! this.isOpen() ) {
				return;
			}

			var self = this;

			this.sheet.classList.remove( 'open' );
			if ( this.scrim ) {
				this.scrim.classList.remove( 'open' );
			}

			document.body.style.overflow = '';
			$$( '[data-cart-open]' ).forEach( function ( el ) {
				el.setAttribute( 'aria-expanded', 'false' );
			} );

			// Wait for the slide-out before removing from the a11y tree.
			window.setTimeout( function () {
				self.sheet.hidden = true;
				if ( self.scrim ) {
					self.scrim.hidden = true;
				}
			}, 280 );

			if ( this.lastFocus && this.lastFocus.focus ) {
				this.lastFocus.focus();
			}
		},

		focusable: function () {
			return $$(
				'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
				this.sheet
			).filter( function ( el ) {
				return null !== el.offsetParent;
			} );
		},

		trapFocus: function ( event ) {
			var items = this.focusable();
			if ( ! items.length ) {
				return;
			}

			var first = items[ 0 ];
			var last = items[ items.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		},
	};

	/* ------------------------------------------------------------------ *
	 * Add to cart
	 * ------------------------------------------------------------------ */

	function applyFragments( fragments ) {
		if ( ! fragments ) {
			return;
		}
		Object.keys( fragments ).forEach( function ( selector ) {
			$$( selector ).forEach( function ( node ) {
				var replacement = document.createRange().createContextualFragment( fragments[ selector ] );
				node.replaceWith( replacement );
			} );
		} );
	}

	function notifyWooCommerce( eventName, payload ) {
		// Other plugins listen on jQuery's bus; mirror to it when present.
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( eventName, payload );
		}
		document.body.dispatchEvent( new CustomEvent( 'mcr:' + eventName, { detail: payload } ) );
	}

	function addToCart( button ) {
		var productId = button.getAttribute( 'data-add-to-cart' );
		if ( ! productId || button.classList.contains( 'is-busy' ) ) {
			return;
		}

		var original = button.innerHTML;
		button.classList.add( 'is-busy' );
		button.disabled = true;

		var body = new URLSearchParams();
		body.append( 'product_id', productId );
		body.append( 'quantity', '1' );

		/*
		 * WooCommerce's own endpoint, not a custom one: stock checks,
		 * sold-individually rules and every woocommerce_add_to_cart_* filter
		 * apply exactly as they do on the product page.
		 */
		window
			.fetch( wooAjaxUrl( 'add_to_cart' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.error && result.product_url ) {
					// Woo is telling us this product needs the full page.
					window.location = result.product_url;
					return;
				}

				applyFragments( result && result.fragments );
				notifyWooCommerce( 'added_to_cart', [ result && result.fragments, result && result.cart_hash, window.jQuery ? window.jQuery( button ) : button ] );

				button.classList.add( 'done' );
				button.innerHTML = original.replace( />[^<]*$/, '>' ) + ( data.i18n ? data.i18n.added : 'Added' );
				announce( ( data.i18n ? data.i18n.added : 'Added' ) + '.' );

				window.setTimeout( function () {
					button.classList.remove( 'done' );
					button.innerHTML = original;
				}, 2600 );
			} )
			.catch( function () {
				button.innerHTML = original;
				announce( data.i18n ? data.i18n.error : 'Could not add that item.' );
			} )
			.finally( function () {
				button.classList.remove( 'is-busy' );
				button.disabled = false;
			} );
	}

	function wooAjaxUrl( action ) {
		if ( window.wc_add_to_cart_params && window.wc_add_to_cart_params.wc_ajax_url ) {
			return window.wc_add_to_cart_params.wc_ajax_url.replace( '%%endpoint%%', action );
		}
		return '/?wc-ajax=' + action;
	}

	function initAddToCart() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-add-to-cart]' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			addToCart( button );
		} );

		// Removing a line from the drawer without a page load.
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '[data-cart-drawer] .line-x' );
			if ( ! link ) {
				return;
			}

			event.preventDefault();
			var row = link.closest( '.line' );
			if ( row ) {
				row.style.opacity = '0.4';
			}

			window
				.fetch( link.href, { credentials: 'same-origin' } )
				.then( function () {
					return window.fetch( wooAjaxUrl( 'get_refreshed_fragments' ), {
						method: 'POST',
						credentials: 'same-origin',
					} );
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( result ) {
					applyFragments( result && result.fragments );
					notifyWooCommerce( 'removed_from_cart', [ result && result.fragments, result && result.cart_hash ] );
					announce( data.i18n ? data.i18n.removed : 'Item removed.' );
				} )
				.catch( function () {
					window.location = link.href;
				} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Wishlist — per-browser, no account required
	 * ------------------------------------------------------------------ */

	var wishlist = {
		key: 'mcr.favs.v1',

		read: function () {
			try {
				return JSON.parse( window.localStorage.getItem( this.key ) ) || [];
			} catch ( e ) {
				// Private mode, blocked storage, or corrupt JSON.
				return [];
			}
		},

		write: function ( ids ) {
			try {
				window.localStorage.setItem( this.key, JSON.stringify( ids ) );
			} catch ( e ) {
				// Quota or blocked storage — the UI still reflects this session.
			}
		},

		toggle: function ( id ) {
			var ids = this.read();
			var index = ids.indexOf( id );

			if ( -1 === index ) {
				ids.push( id );
			} else {
				ids.splice( index, 1 );
			}

			this.write( ids );
			this.paint();

			return -1 === index;
		},

		paint: function () {
			var ids = this.read();

			$$( '[data-wishlist-count]' ).forEach( function ( badge ) {
				badge.textContent = String( ids.length );
				badge.hidden = 0 === ids.length;
			} );

			$$( '[data-wishlist-toggle]' ).forEach( function ( button ) {
				var active = -1 !== ids.indexOf( button.getAttribute( 'data-wishlist-toggle' ) );
				button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			} );
		},

		init: function () {
			var self = this;

			document.addEventListener( 'click', function ( event ) {
				var button = event.target.closest( '[data-wishlist-toggle]' );
				if ( ! button ) {
					return;
				}
				event.preventDefault();
				self.toggle( button.getAttribute( 'data-wishlist-toggle' ) );
			} );

			this.paint();
		},
	};

	/* ------------------------------------------------------------------ *
	 * Filter sheet (mobile)
	 * ------------------------------------------------------------------ */

	function initFilters() {
		var panel = $( '#filters' );
		var open = $( '#fopen' );
		var close = $( '#fclose' );

		if ( ! panel || ! open ) {
			return;
		}

		function setOpen( isOpen ) {
			panel.classList.toggle( 'open', isOpen );
			open.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
			document.body.style.overflow = isOpen ? 'hidden' : '';

			if ( isOpen ) {
				var first = panel.querySelector( 'input, button, a' );
				if ( first ) {
					first.focus();
				}
			} else {
				open.focus();
			}
		}

		open.addEventListener( 'click', function () {
			setOpen( ! panel.classList.contains( 'open' ) );
		} );

		if ( close ) {
			close.addEventListener( 'click', function () {
				setOpen( false );
			} );
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && panel.classList.contains( 'open' ) ) {
				setOpen( false );
			}
		} );

		// Sort control submits itself.
		var ordering = $( '.woocommerce-ordering' );
		if ( ordering ) {
			var select = ordering.querySelector( 'select' );
			if ( select ) {
				select.addEventListener( 'change', function () {
					ordering.submit();
				} );
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Boot
	 * ------------------------------------------------------------------ */

	function init() {
		drawer.init();
		initAddToCart();
		wishlist.init();
		initFilters();

		// Keep the wishlist badge honest across tabs.
		window.addEventListener( 'storage', function ( event ) {
			if ( event.key === wishlist.key ) {
				wishlist.paint();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
