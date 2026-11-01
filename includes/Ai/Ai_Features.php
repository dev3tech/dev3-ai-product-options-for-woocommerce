<?php
/**
 * DEV3 AI features: generate options, logic review, formula helper, labels/translations, upload vision check.
 * Every admin result is a draft; nothing here saves fields.
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Ai;

use Dev3\AiProductOptions\Core3\Api\Api_Error;
use Dev3\AiProductOptions\Core3\Api\Prompts;
use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Engine\Formula;
use Dev3\AiProductOptions\Engine\Logic_Checker;
use Dev3\AiProductOptions\Engine\Pricing;
use Dev3\AiProductOptions\Fields\Field_Schema;
use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Feature implementations.
 */
class Ai_Features {

	const FEATURES = array( 'ai_generate', 'ai_logic', 'ai_formula', 'ai_labels', 'ai_vision' );

	/**
	 * Feature switch guard.
	 *
	 * @param string $feature Setting key.
	 * @return \WP_Error|null
	 */
	private static function feature_off( $feature ) {
		if ( Settings_Store::get( $feature, true ) ) {
			return null;
		}
		return Api_Error::make( 'dev3po_feature_off', __( 'This DEV3 AI feature is turned off.', 'dev3-ai-product-options-for-woocommerce' ), __( 'Turn it on in Settings › DEV3 AI features.', 'dev3-ai-product-options-for-woocommerce' ), 403 );
	}

	/**
	 * Product context for prompts.
	 *
	 * @param array $ctx { product_id?, title?, description?, price? }.
	 * @return array
	 */
	public static function product_context( array $ctx ) {
		$out     = array(
			'title'       => mb_substr( sanitize_text_field( (string) ( $ctx['title'] ?? '' ) ), 0, 200 ),
			'description' => mb_substr( sanitize_textarea_field( (string) ( $ctx['description'] ?? '' ) ), 0, 2500 ),
			'price'       => is_numeric( $ctx['price'] ?? null ) ? (float) $ctx['price'] : 0.0,
			'type'        => 'simple',
			'categories'  => '',
			'attributes'  => 'none',
		);
		$product = ! empty( $ctx['product_id'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $ctx['product_id'] ) : null;
		if ( $product ) {
			if ( '' === $out['title'] ) {
				$out['title'] = $product->get_name();
			}
			if ( '' === $out['description'] ) {
				$out['description'] = mb_substr( trim( wp_strip_all_tags( $product->get_short_description() . "\n" . $product->get_description() ) ), 0, 2500 );
			}
			if ( ! $out['price'] ) {
				$out['price'] = (float) $product->get_price();
			}
			$out['type']       = $product->get_type();
			$terms             = get_the_terms( $product->get_id(), 'product_cat' );
			$out['categories'] = is_array( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
			$attrs             = array();
			foreach ( $product->get_attributes() as $attr ) {
				if ( is_object( $attr ) && $attr->get_variation() ) {
					$attrs[] = wc_attribute_label( $attr->get_name() );
				}
			}
			$out['attributes'] = $attrs ? implode( ', ', $attrs ) : 'none';
		}
		return $out;
	}

	/**
	 * "Generate options": builds a full option set (draft) from the product.
	 *
	 * @param array $ctx { product_id?, title?, description?, price?, instructions? }.
	 * @return array|\WP_Error { fields, notes, issues, schema_errors, model, tokens, cached }
	 */
	public static function generate_options( array $ctx ) {
		$off = self::feature_off( 'ai_generate' );
		if ( $off ) {
			return $off;
		}
		$product = self::product_context( $ctx );
		if ( '' === $product['title'] && '' === $product['description'] ) {
			return Api_Error::make( 'dev3po_no_context', __( 'Add a product title or description first.', 'dev3-ai-product-options-for-woocommerce' ), '', 400 );
		}
		Prompt_Library::register();
		$result = Prompts::run(
			'generate_options',
			array(
				'title'        => $product['title'],
				'type'         => $product['type'],
				'price'        => wc_format_decimal( $product['price'], 2 ),
				'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'categories'   => '' !== $product['categories'] ? $product['categories'] : 'none',
				'attributes'   => $product['attributes'],
				'description'  => '' !== $product['description'] ? $product['description'] : '(none)',
				'instructions' => mb_substr( sanitize_textarea_field( (string) ( $ctx['instructions'] ?? '' ) ), 0, 600 ),
				'language'     => self::language( $ctx['locale'] ?? '' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data   = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$errors = Field_Schema::validate_generated( $data );
		$fields = Field_Schema::normalize_list( self::from_generated( $data['fields'] ?? array() ) );
		if ( ! $fields ) {
			return Api_Error::make( 'dev3po_empty_draft', __( 'DEV3 AI did not return usable options.', 'dev3-ai-product-options-for-woocommerce' ), __( 'Add more detail to the description or instructions, then regenerate.', 'dev3-ai-product-options-for-woocommerce' ), 502, true );
		}
		$base = $product['price'] ? $product['price'] : 10.0;
		return array(
			'fields'        => $fields,
			'notes'         => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'issues'        => Logic_Checker::check( $fields ),
			'schema_errors' => $errors,
			'sample'        => Pricing::calculate( $fields, self::sample_values( $fields ), $base, 1 )['total'],
			'model'         => $result['model'],
			'tokens'        => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'        => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Maps generated keys to the field shape (condition → conditions, operator → op).
	 *
	 * @param mixed $items_in Generated fields.
	 * @return array
	 */
	public static function from_generated( $items_in ) {
		$out = array();
		foreach ( is_array( $items_in ) ? $items_in : array() as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			if ( isset( $f['condition'] ) && is_array( $f['condition'] ) ) {
				$rules = array();
				foreach ( (array) ( $f['condition']['rules'] ?? array() ) as $r ) {
					if ( is_array( $r ) ) {
						$rules[] = array(
							'field' => (string) ( $r['field'] ?? '' ),
							'op'    => (string) ( $r['operator'] ?? 'is' ),
							'value' => (string) ( $r['value'] ?? '' ),
						);
					}
				}
				$f['conditions'] = array(
					'action' => $f['condition']['action'] ?? 'show',
					'match'  => $f['condition']['match'] ?? 'all',
					'rules'  => $rules,
				);
				unset( $f['condition'] );
			}
			$out[] = $f;
		}
		return $out;
	}

	/**
	 * Values that "fill" every field (first option, 1, 'Sample') for a sample price.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function sample_values( array $fields ) {
		$values = array();
		foreach ( $fields as $f ) {
			switch ( Field_Types::kind( $f['type'] ) ) {
				case 'text':
					$values[ $f['id'] ] = 'Sample';
					break;
				case 'number':
					$values[ $f['id'] ] = null !== ( $f['min'] ?? null ) ? (float) $f['min'] : 1.0;
					break;
				case 'choice':
					$values[ $f['id'] ] = $f['options'][0]['id'] ?? '';
					break;
			}
		}
		return $values;
	}

	/**
	 * Logic checker + DEV3 AI review.
	 *
	 * @param array  $fields  Normalised fields.
	 * @param string $product Product/set name.
	 * @return array|\WP_Error { summary, issues, model, tokens, cached }
	 */
	public static function review_logic( array $fields, $product = '' ) {
		$off = self::feature_off( 'ai_logic' );
		if ( $off ) {
			return $off;
		}
		$issues = Logic_Checker::check( $fields );
		Prompt_Library::register();
		$result = Prompts::run(
			'review_logic',
			array(
				'product' => '' !== $product ? $product : '(unnamed)',
				'fields'  => self::compact( $fields ),
				'issues'  => $issues ? implode( "\n", wp_list_pluck( $issues, 'message' ) ) : 'none',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$keys = array();
		foreach ( $fields as $f ) {
			$keys[ $f['key'] ] = $f['id'];
		}
		foreach ( (array) ( $result['data']['issues'] ?? array() ) as $ai ) {
			if ( ! is_array( $ai ) || empty( $ai['message'] ) ) {
				continue;
			}
			$issues[] = array(
				'code'       => 'ai_review',
				'severity'   => in_array( $ai['severity'] ?? '', array( 'error', 'warning', 'info' ), true ) ? $ai['severity'] : 'warning',
				'field'      => $keys[ sanitize_key( (string) ( $ai['field'] ?? '' ) ) ] ?? '',
				'message'    => sanitize_text_field( (string) $ai['message'] ),
				'suggestion' => sanitize_text_field( (string) ( $ai['suggestion'] ?? '' ) ),
				'source'     => 'ai',
			);
		}
		return array(
			'summary' => sanitize_text_field( (string) ( $result['data']['summary'] ?? '' ) ),
			'issues'  => $issues,
			'model'   => $result['model'],
			'tokens'  => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'  => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Formula helper: plain-language rule → validated formula + worked examples.
	 *
	 * @param string $rule   Rule text.
	 * @param array  $field  The field being priced.
	 * @param array  $fields All fields (for {key} variables).
	 * @return array|\WP_Error { formula, explanation, examples, model, tokens }
	 */
	public static function formula( $rule, array $field, array $fields ) {
		$off = self::feature_off( 'ai_formula' );
		if ( $off ) {
			return $off;
		}
		$rule = mb_substr( trim( sanitize_textarea_field( (string) $rule ) ), 0, 400 );
		if ( '' === $rule ) {
			return Api_Error::make( 'dev3po_empty_rule', __( 'Describe the pricing rule first.', 'dev3-ai-product-options-for-woocommerce' ), __( 'For example: "add 2 per letter over 10 letters".', 'dev3-ai-product-options-for-woocommerce' ), 400 );
		}
		$vars = array( '{price} product price', '{qty} quantity', '{value} this field as a number', '{len} characters in this field', '{words}', '{lines}', '{count} selected choices or files' );
		foreach ( $fields as $f ) {
			if ( Field_Types::is_input( $f['type'] ) && $f['id'] !== $field['id'] ) {
				$vars[] = '{' . $f['key'] . '} ' . $f['label'];
			}
		}
		Prompt_Library::register();
		$feedback = '';
		$tokens   = 0;
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$result = Prompts::run(
				'formula',
				array(
					'label'    => $field['label'],
					'type'     => $field['type'],
					'key'      => $field['key'],
					'vars'     => implode( '; ', $vars ),
					'rule'     => $rule,
					'language' => self::language(),
					'feedback' => $feedback,
				),
				'admin',
				$attempt ? array( 'cache' => 0 ) : array()
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$tokens += (int) ( $result['usage']['total'] ?? 0 );
			$formula = trim( (string) ( $result['data']['formula'] ?? '' ) );
			$problem = self::formula_problem( $formula, $field, $fields );
			if ( '' === $problem ) {
				return array(
					'formula'     => $formula,
					'explanation' => sanitize_text_field( (string) ( $result['data']['explanation'] ?? '' ) ),
					'examples'    => self::formula_examples( $formula, $field, $fields ),
					'model'       => $result['model'],
					'tokens'      => $tokens,
					'cached'      => ! empty( $result['cached'] ),
				);
			}
			$feedback = "\nYour previous answer \"{$formula}\" was rejected: {$problem} Return a corrected formula.";
		}
		return Api_Error::make( 'dev3po_formula_invalid', __( 'DEV3 AI could not produce a valid formula for that rule.', 'dev3-ai-product-options-for-woocommerce' ), __( 'Rephrase the rule, or write the formula yourself.', 'dev3-ai-product-options-for-woocommerce' ), 422, true );
	}

	/**
	 * Why a formula is not acceptable ('' = fine).
	 *
	 * @param string $formula Formula.
	 * @param array  $field   Field.
	 * @param array  $fields  Fields.
	 * @return string
	 */
	public static function formula_problem( $formula, array $field, array $fields ) {
		$compiled = Formula::compile( $formula );
		if ( ! $compiled['ok'] ) {
			return Formula::error_message( $compiled['error'] );
		}
		$keys = array();
		foreach ( $fields as $f ) {
			$keys[ $f['key'] ] = true;
		}
		foreach ( $compiled['vars'] as $var ) {
			$base = explode( '.', $var )[0];
			if ( ! in_array( $var, Logic_Checker::BUILTIN_VARS, true ) && ! isset( $keys[ $base ] ) ) {
				return "Unknown variable {{$var}}.";
			}
		}
		unset( $field );
		return '';
	}

	/**
	 * Worked examples so the admin can verify a formula.
	 *
	 * @param string $formula Formula.
	 * @param array  $field   Field.
	 * @param array  $fields  Fields.
	 * @return array [ { input, price } ]
	 */
	public static function formula_examples( $formula, array $field, array $fields ) {
		unset( $fields );
		$kind    = Field_Types::kind( $field['type'] );
		$samples = 'text' === $kind ? array( 0, 5, 10, 12, 20 ) : array( 0, 1, 5, 10, 25 );
		$out     = array();
		foreach ( $samples as $n ) {
			$vars  = array(
				'price' => 20,
				'qty'   => 1,
				'value' => $n,
				'len'   => 'text' === $kind ? $n : 0,
				'words' => 'text' === $kind ? (int) ceil( $n / 5 ) : 0,
				'lines' => $n ? 1 : 0,
				'count' => $n,
			);
			$out[] = array(
				'input' => 'text' === $kind
					/* translators: %d: number of characters */
					? sprintf( _n( '%d character', '%d characters', $n, 'dev3-ai-product-options-for-woocommerce' ), $n )
					/* translators: %s: value */
					: sprintf( __( 'value %s', 'dev3-ai-product-options-for-woocommerce' ), $n ),
				'price' => Pricing::round_money( Formula::evaluate( $formula, $vars ) ),
			);
		}
		return $out;
	}

	/**
	 * Labels/help/tooltips (improve) or translations (translate) as a draft.
	 *
	 * @param array  $fields  Fields.
	 * @param string $mode    improve|translate.
	 * @param string $locale  Target locale (translate) or '' (site language).
	 * @param string $tone    Tone.
	 * @param string $product Product/set name.
	 * @return array|\WP_Error { mode, locale, draft: [ { id, label, help, tooltip, placeholder, options{ id: label } } ], model, tokens }
	 */
	public static function labels( array $fields, $mode, $locale = '', $tone = 'friendly', $product = '' ) {
		$off = self::feature_off( 'ai_labels' );
		if ( $off ) {
			return $off;
		}
		$mode   = 'translate' === $mode ? 'translate' : 'improve';
		$locale = preg_replace( '/[^A-Za-z_]/', '', (string) $locale );
		if ( 'translate' === $mode && '' === $locale ) {
			return Api_Error::make( 'dev3po_no_locale', __( 'Choose a language to translate into.', 'dev3-ai-product-options-for-woocommerce' ), '', 400 );
		}
		$items = array();
		foreach ( $fields as $f ) {
			if ( 'hidden' === $f['type'] ) {
				continue;
			}
			$item = array(
				'id'          => $f['id'],
				'type'        => $f['type'],
				'label'       => $f['label'],
				'help'        => $f['help'],
				'tooltip'     => $f['tooltip'],
				'placeholder' => $f['placeholder'],
			);
			if ( ! empty( $f['options'] ) ) {
				$item['options'] = array_map(
					static fn( $o ) => array(
						'id'    => $o['id'],
						'label' => $o['label'],
					),
					$f['options']
				);
			}
			$items[] = $item;
		}
		if ( ! $items ) {
			return Api_Error::make( 'dev3po_no_fields', __( 'Add some fields first.', 'dev3-ai-product-options-for-woocommerce' ), '', 400 );
		}
		Prompt_Library::register();
		$result = Prompts::run(
			'labels',
			array(
				'tone'     => in_array( $tone, array( 'friendly', 'professional', 'playful', 'luxury', 'concise' ), true ) ? $tone : 'friendly',
				'language' => self::language( 'translate' === $mode ? $locale : '' ),
				'product'  => '' !== $product ? $product : '(unnamed)',
				'task'     => 'translate' === $mode ? 'Translate every label, help text, tooltip, placeholder and option label into ' . self::language( $locale ) . '. Keep empty values empty.' : 'Rewrite the labels so they are clear and consistent, and write missing help texts and tooltips where they help the shopper decide.',
				'fields'   => $items,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$known = array();
		foreach ( $fields as $f ) {
			$known[ $f['id'] ] = $f;
		}
		$draft = array();
		foreach ( (array) ( $result['data']['fields'] ?? array() ) as $row ) {
			$id = is_array( $row ) ? (string) ( $row['id'] ?? '' ) : '';
			if ( ! isset( $known[ $id ] ) ) {
				continue;
			}
			$entry = array( 'id' => $id );
			foreach ( array(
				'label'       => 120,
				'help'        => 300,
				'tooltip'     => 300,
				'placeholder' => 120,
			) as $k => $max ) {
				$entry[ $k ] = mb_substr( sanitize_text_field( (string) ( $row[ $k ] ?? '' ) ), 0, $max );
			}
			$entry['options'] = array();
			$option_ids       = wp_list_pluck( $known[ $id ]['options'] ?? array(), 'id' );
			foreach ( (array) ( $row['options'] ?? array() ) as $opt ) {
				if ( is_array( $opt ) && in_array( (string) ( $opt['id'] ?? '' ), $option_ids, true ) && '' !== trim( (string) ( $opt['label'] ?? '' ) ) ) {
					$entry['options'][ (string) $opt['id'] ] = mb_substr( sanitize_text_field( (string) $opt['label'] ), 0, 120 );
				}
			}
			$draft[] = $entry;
		}
		if ( ! $draft ) {
			return Api_Error::make( 'dev3po_empty_draft', __( 'DEV3 AI did not return usable text.', 'dev3-ai-product-options-for-woocommerce' ), __( 'Try again in a moment.', 'dev3-ai-product-options-for-woocommerce' ), 502, true );
		}
		return array(
			'mode'   => $mode,
			'locale' => $locale,
			'draft'  => $draft,
			'model'  => $result['model'],
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Gemini vision check of an uploaded image (storefront context: rate-limited, cached, no retries).
	 * Falls back to "unchecked" whenever AI is unavailable, so uploads never break.
	 *
	 * @param string $path    Absolute file path.
	 * @param string $mime    MIME.
	 * @param array  $field   Field.
	 * @param int    $width   Width.
	 * @param int    $height  Height.
	 * @param string $product Product name.
	 * @return array { status: ok|warn|fail|unchecked, message, score }
	 */
	public static function vision_check( $path, $mime, array $field, $width, $height, $product = '' ) {
		$unchecked = array(
			'status'  => 'unchecked',
			'message' => '',
			'score'   => null,
		);
		if ( empty( $field['vision'] ) || ! Settings_Store::get( 'ai_vision', true ) || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return $unchecked;
		}
		$data = self::image_payload( $path, $mime );
		if ( ! $data ) {
			return $unchecked;
		}
		Prompt_Library::register();
		$result = Prompts::run(
			'vision_check',
			array(
				'product'  => '' !== $product ? $product : '(product)',
				'label'    => $field['label'],
				'rule'     => '' !== $field['vision_rule'] ? $field['vision_rule'] : 'A clear, well-lit photo suitable for printing.',
				'width'    => (int) $width,
				'height'   => (int) $height,
				'language' => self::language(),
			),
			'storefront',
			array( 'images' => array( $data ) )
		);
		if ( is_wp_error( $result ) ) {
			return $unchecked;
		}
		$fit     = ! empty( $result['data']['fit'] );
		$score   = max( 0, min( 100, (int) ( $result['data']['score'] ?? 0 ) ) );
		$message = mb_substr( sanitize_text_field( (string) ( $result['data']['message'] ?? '' ) ), 0, 160 );
		$status  = $fit ? ( $score >= 60 ? 'ok' : 'warn' ) : ( 'block' === Settings_Store::get( 'vision_mode', 'warn' ) ? 'fail' : 'warn' );
		return array(
			'status'  => $status,
			'message' => $message,
			'score'   => $score,
		);
	}

	/**
	 * Inline image data, downscaled to 1024px when large.
	 *
	 * @param string $path Path.
	 * @param string $mime MIME.
	 * @return array|null { mime, data }
	 */
	private static function image_payload( $path, $mime ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$source = $path;
		$tmp    = '';
		if ( filesize( $path ) > 1500000 || 'image/gif' === $mime ) {
			$editor = wp_get_image_editor( $path );
			if ( ! is_wp_error( $editor ) ) {
				$editor->resize( 1024, 1024, false );
				$tmp   = wp_tempnam( 'dev3po-vision' ) . '.jpg';
				$saved = $editor->save( $tmp, 'image/jpeg' );
				if ( ! is_wp_error( $saved ) ) {
					$source = $saved['path'];
					$mime   = 'image/jpeg';
				}
			}
		}
		$bytes = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' !== $tmp && is_file( $source ) && $source !== $path ) {
			wp_delete_file( $source );
		}
		if ( false === $bytes || strlen( $bytes ) > 4000000 ) {
			return null;
		}
		return array(
			'mime' => $mime,
			'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Gemini inline image data.
		);
	}

	/**
	 * Compact field JSON for prompts (keys instead of ids in conditions).
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function compact( array $fields ) {
		$keys = array();
		foreach ( $fields as $f ) {
			$keys[ $f['id'] ] = $f['key'];
		}
		$out = array();
		foreach ( $fields as $f ) {
			$item = array(
				'key'      => $f['key'],
				'type'     => $f['type'],
				'label'    => $f['label'],
				'required' => $f['required'],
			);
			if ( 'none' !== $f['price_type'] ) {
				$item['price_type'] = $f['price_type'];
				$item['price']      = $f['price'];
				$item['charge']     = $f['charge'];
				if ( 'formula' === $f['price_type'] ) {
					$item['formula'] = $f['formula'];
				}
			}
			foreach ( array( 'min', 'max', 'maxlength' ) as $k ) {
				if ( isset( $f[ $k ] ) && null !== $f[ $k ] && '' !== $f[ $k ] && 0 !== $f[ $k ] ) {
					$item[ $k ] = $f[ $k ];
				}
			}
			if ( ! empty( $f['options'] ) ) {
				$item['options'] = array_map(
					static fn( $o ) => array(
						'label' => $o['label'],
						'price' => $o['price'],
						'type'  => $o['price_type'],
					),
					$f['options']
				);
			}
			if ( ! empty( $f['conditions']['enabled'] ) ) {
				$labels = array();
				foreach ( $fields as $t ) {
					foreach ( $t['options'] ?? array() as $o ) {
						$labels[ $o['id'] ] = $o['label'];
					}
				}
				$item['condition'] = array(
					'action' => $f['conditions']['action'],
					'match'  => $f['conditions']['match'],
					'rules'  => array_map(
						static fn( $r ) => array(
							'field'    => $keys[ $r['field'] ] ?? '(missing)',
							'operator' => $r['op'],
							'value'    => $labels[ $r['value'] ] ?? $r['value'],
						),
						$f['conditions']['rules']
					),
				);
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Language label for prompts.
	 *
	 * @param string $locale Locale ('' = site).
	 * @return string
	 */
	public static function language( $locale = '' ) {
		$locale = '' !== $locale ? $locale : get_locale();
		$names  = array(
			'en' => 'English',
			'de' => 'German',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'pt' => 'Portuguese',
			'pl' => 'Polish',
			'sv' => 'Swedish',
			'da' => 'Danish',
			'fi' => 'Finnish',
			'nb' => 'Norwegian',
			'cs' => 'Czech',
			'tr' => 'Turkish',
			'ru' => 'Russian',
			'uk' => 'Ukrainian',
			'ar' => 'Arabic',
			'he' => 'Hebrew',
			'hi' => 'Hindi',
			'ja' => 'Japanese',
			'ko' => 'Korean',
			'zh' => 'Chinese',
			'th' => 'Thai',
			'vi' => 'Vietnamese',
			'id' => 'Indonesian',
			'el' => 'Greek',
			'ro' => 'Romanian',
			'hu' => 'Hungarian',
		);
		$code   = strtolower( substr( $locale, 0, 2 ) );
		return ( $names[ $code ] ?? $locale ) . ' (' . $locale . ')';
	}
}
