/**
 * @jest-environment jest-fixed-jsdom
 */

describe( 'VariationForm lazy variation data', () => {
	let $form;
	let eventHandlers;
	let jQueryMock;
	let setTimeoutSpy;

	const createMockCollection = () => {
		const collection = {
			length: 0,
			addBack: jest.fn( () => collection ),
			addClass: jest.fn( () => collection ),
			after: jest.fn( () => collection ),
			attr: jest.fn( () => collection ),
			block: jest.fn( () => collection ),
			closest: jest.fn( () => collection ),
			css: jest.fn( () => collection ),
			data: jest.fn(),
			each: jest.fn( () => collection ),
			eq: jest.fn( () => collection ),
			fadeIn: jest.fn( () => collection ),
			filter: jest.fn( () => collection ),
			find: jest.fn( () => createMockCollection() ),
			first: jest.fn( () => collection ),
			hide: jest.fn( () => collection ),
			html: jest.fn( () => collection ),
			is: jest.fn( () => false ),
			not: jest.fn( () => collection ),
			off: jest.fn( () => collection ),
			on: jest.fn( () => collection ),
			parent: jest.fn( () => collection ),
			remove: jest.fn( () => collection ),
			removeClass: jest.fn( () => collection ),
			show: jest.fn( () => collection ),
			slideDown: jest.fn( () => collection ),
			slideUp: jest.fn( () => collection ),
			text: jest.fn( () => '' ),
			trigger: jest.fn( () => collection ),
			unblock: jest.fn( () => collection ),
			val: jest.fn( () => collection ),
		};

		return collection;
	};

	const createForm = ( data ) => {
		const attributeFields = createMockCollection();
		const singleVariation = createMockCollection();
		const singleVariationWrap = createMockCollection();
		const product = createMockCollection();

		$form = createMockCollection();
		$form.find = jest.fn( ( selector ) => {
			if ( '.variations select' === selector ) {
				return attributeFields;
			}
			if ( '.single_variation' === selector ) {
				return singleVariation;
			}
			if ( '.single_variation_wrap' === selector ) {
				return singleVariationWrap;
			}
			return createMockCollection();
		} );
		$form.closest = jest.fn( () => product );
		$form.data = jest.fn( ( key ) => data[ key ] );
		$form.on = jest.fn( ( eventName, ...args ) => {
			eventHandlers[ eventName.split( '.' )[ 0 ] ] = {
				data: args.find(
					( arg ) =>
						arg &&
						typeof arg === 'object' &&
						Object.prototype.hasOwnProperty.call(
							arg,
							'variationForm'
						)
				),
				handler: args.find( ( arg ) => 'function' === typeof arg ),
			};
			return $form;
		} );

		return $form;
	};

	const triggerCheckVariations = ( chosenAttributes ) => {
		const event = {
			data: eventHandlers.check_variations.data,
		};

		eventHandlers.check_variations.handler( event, chosenAttributes );
	};

	beforeEach( () => {
		eventHandlers = {};
		jest.resetModules();

		setTimeoutSpy = jest
			.spyOn( global, 'setTimeout' )
			.mockImplementation( () => 0 );

		jQueryMock = jest.fn( ( selectorOrCallback ) => {
			if ( 'function' === typeof selectorOrCallback ) {
				selectorOrCallback();
				return jQueryMock;
			}

			if ( '.variations_form' === selectorOrCallback ) {
				return {
					each: jest.fn(),
				};
			}

			return createMockCollection();
		} );
		jQueryMock.ajax = jest.fn( () => ( {
			abort: jest.fn(),
		} ) );
		jQueryMock.extend = jest.fn( ( target, source ) =>
			Object.assign( target, source )
		);
		jQueryMock.fn = {};
		jQueryMock.isArray = Array.isArray;

		global.window.jQuery = jQueryMock;
		global.window.$ = jQueryMock;
		global.jQuery = jQueryMock;
		global.$ = jQueryMock;
		global.wc_add_to_cart_variation_params = {
			wc_ajax_url: '/?wc-ajax=%%endpoint%%',
			i18n_make_a_selection_text: 'Choose options',
			i18n_no_matching_variations_text: 'No matching variations',
			i18n_reset_alert_text: 'Selection reset',
			i18n_unavailable_text: 'Unavailable',
		};
		global.window.wc_add_to_cart_variation_params =
			global.wc_add_to_cart_variation_params;

		require( '../add-to-cart-variation' );
	} );

	afterEach( () => {
		setTimeoutSpy.mockRestore();
	} );

	it( 'fetches the full variation payload after matching lazy inline data', () => {
		createForm( {
			product_id: 123,
			product_variations: [
				{
					attributes: {
						attribute_pa_size: 'small',
					},
					variation_data_loaded: false,
					variation_id: 456,
					variation_is_active: true,
					variation_is_visible: true,
				},
			],
			product_variations_lazy: true,
		} );

		jQueryMock.fn.wc_variation_form.call( $form );

		triggerCheckVariations( {
			count: 1,
			chosenCount: 1,
			data: {
				attribute_pa_size: 'small',
			},
		} );

		expect( jQueryMock.ajax ).toHaveBeenCalledWith(
			expect.objectContaining( {
				data: {
					attribute_pa_size: 'small',
					custom_data: undefined,
					product_id: 123,
				},
				type: 'POST',
				url: '/?wc-ajax=get_variation',
			} )
		);
		expect( $form.trigger ).not.toHaveBeenCalledWith(
			'found_variation',
			expect.anything()
		);
	} );

	it( 'uses cached full variation data without a second AJAX request', () => {
		const loadedVariation = {
			attributes: {
				attribute_pa_size: 'small',
			},
			price_html: '<span class="price">$10</span>',
			variation_data_loaded: true,
			variation_id: 456,
			variation_is_active: true,
			variation_is_visible: true,
		};

		createForm( {
			product_id: 123,
			product_variations: [ loadedVariation ],
			product_variations_lazy: true,
		} );

		jQueryMock.fn.wc_variation_form.call( $form );

		triggerCheckVariations( {
			count: 1,
			chosenCount: 1,
			data: {
				attribute_pa_size: 'small',
			},
		} );

		expect( jQueryMock.ajax ).not.toHaveBeenCalled();
		expect( $form.trigger ).toHaveBeenCalledWith( 'found_variation', [
			loadedVariation,
		] );
	} );
} );
