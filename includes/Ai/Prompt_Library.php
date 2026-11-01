<?php
/**
 * Versioned prompts + response schemas for every DEV3 AI feature (overridable via dev3po_prompt_<id>).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Ai;

use Dev3\AiProductOptions\Core3\Api\Prompts;
use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Registers prompts once per request.
 */
class Prompt_Library {

	const GENERATED_TYPES = array( 'text', 'textarea', 'email', 'tel', 'number', 'range', 'select', 'radio', 'checkboxes', 'color_swatch', 'checkbox', 'toggle', 'date', 'time', 'file', 'color', 'section' );

	const FORMULA_GUIDE = 'Formula grammar: numbers, + - * / % ^, parentheses, comparisons (== != > < >= <=, result 1 or 0), && ||, functions min(a,b,..) max(..) round(x[,decimals]) ceil(x) floor(x) abs(x) sqrt(x) pow(a,b) if(cond,a,b) clamp(x,lo,hi). Variables in braces: {price} product unit price, {qty} quantity, {value} numeric value of this field, {len} characters typed in this field (spaces not counted), {words}, {lines}, {count} number of selected choices or files, and {other_key} / {other_key.len} / {other_key.count} for other fields by key. The result is the add-on price per item.';

	/**
	 * Registers all prompts.
	 */
	public static function register() {
		if ( Prompts::versions() && null !== Prompts::get( 'generate_options' ) ) {
			return;
		}

		Prompts::register(
			'generate_options',
			array(
				'version'     => '1.2',
				'task'        => 'generate_options',
				'system'      => "You are a senior WooCommerce merchandiser who designs product option forms (add-ons). Design the option set a real shop would sell for the product: personalisation, upgrades, gift options, sizes or materials that are NOT already WooCommerce variations, and any required inputs. Rules:\n- 3 to 12 fields, ordered the way a shopper decides. Use clear, short labels in {{language}}.\n- key: unique snake_case, max 30 chars.\n- Prices are in {{currency}} and must be realistic for a product that costs {{price}}. Use price_type flat for fixed extras, percent for upgrades proportional to the price, per_char for engraving/printing text, per_unit for quantities, formula only when needed.\n- Choice fields (select, radio, checkboxes, color_swatch) need options; each option has its own price (0 when free).\n- condition.rules[].field is the key of an EARLIER field; for choice fields value is the option label; use operator checked for checkbox/toggle.\n- Never create circular conditions. Do not invent discounts.\n- " . self::FORMULA_GUIDE,
				'template'    => "Product title: {{title}}\nProduct type: {{type}}\nPrice: {{price}} {{currency}}\nCategories: {{categories}}\nExisting variation attributes (do not duplicate): {{attributes}}\nDescription:\n{{description}}\n\nMerchant instructions: {{instructions}}\n\nReturn the option set as JSON.",
				'schema'      => self::generate_schema(),
				'temperature' => 0.3,
				'profile'     => 'heavy',
				'max_tokens'  => 8192,
				'cache'       => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'review_logic',
			array(
				'version'     => '1.1',
				'task'        => 'review_logic',
				'system'      => 'You review WooCommerce product option sets before they go live. Find problems a shopper or the shop would hit: impossible or contradictory conditions, circular dependencies, fields that can never show, required fields hidden by other choices, price conflicts (double charging, options priced inconsistently, percent vs flat confusion, negative totals), confusing labels and missing help. An automatic checker already found the issues listed under "Checker issues"; do not repeat them, add only new findings. Reference fields by key. Be concrete and brief. If the set is fine, return an empty list.',
				'template'    => "Product: {{product}}\nFields (JSON):\n{{fields}}\n\nChecker issues:\n{{issues}}",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary' => array(
							'type'      => 'STRING',
							'maxLength' => 400,
						),
						'issues'  => array(
							'type'     => 'ARRAY',
							'maxItems' => 15,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'severity'   => array(
										'type' => 'STRING',
										'enum' => array( 'error', 'warning', 'info' ),
									),
									'field'      => array(
										'type'      => 'STRING',
										'maxLength' => 40,
									),
									'message'    => array(
										'type'      => 'STRING',
										'maxLength' => 240,
									),
									'suggestion' => array(
										'type'      => 'STRING',
										'maxLength' => 240,
									),
								),
								'required'   => array( 'severity', 'message' ),
							),
						),
					),
					'required'   => array( 'summary', 'issues' ),
				),
				'temperature' => 0.2,
				'cache'       => DAY_IN_SECONDS,
			)
		);

		Prompts::register(
			'formula',
			array(
				'version'     => '1.1',
				'task'        => 'formula',
				'system'      => 'You turn a pricing rule written in plain language into a price formula for a WooCommerce product option. ' . self::FORMULA_GUIDE . ' Use only the variables listed as available. Prefer max(0, …) so prices never go negative. Explain the formula in one or two short sentences in {{language}}.',
				'template'    => "Field: {{label}} (type {{type}}, key {{key}})\nAvailable variables: {{vars}}\nRule: {{rule}}{{feedback}}",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'formula'     => array(
							'type'      => 'STRING',
							'maxLength' => 200,
						),
						'explanation' => array(
							'type'      => 'STRING',
							'maxLength' => 300,
						),
					),
					'required'   => array( 'formula', 'explanation' ),
				),
				'temperature' => 0.1,
				'profile'     => 'fast',
				'cache'       => WEEK_IN_SECONDS,
			)
		);

		Prompts::register(
			'labels',
			array(
				'version'     => '1.1',
				'task'        => 'labels',
				'system'      => 'You write the microcopy of WooCommerce product options: labels (2-5 words), help text (one short sentence that removes doubt, or empty), tooltips (one sentence with a useful detail, or empty) and placeholders (an example value, or empty). Tone: {{tone}}. Language: {{language}}. Keep every id unchanged and return every field and option you received. Do not mention prices, the shop name or AI.',
				'template'    => "Product: {{product}}\nTask: {{task}}\nFields (JSON):\n{{fields}}",
				'schema'      => self::labels_schema(),
				'temperature' => 0.3,
				'max_tokens'  => 8192,
				'cache'       => WEEK_IN_SECONDS,
			)
		);

		Prompts::register(
			'vision_check',
			array(
				'version'     => '1.0',
				'task'        => 'vision_check',
				'system'      => 'You check images that shoppers upload for a personalised product. Decide whether the image fits the purpose and is good enough to print or produce: subject matches the requirement, not blurry, not too dark, not cropped badly, no watermark, no offensive content. The message is shown to the shopper: friendly, neutral, max 160 characters, in {{language}}, and says how to fix the problem when the image does not fit.',
				'template'    => "Product: {{product}}\nUpload field: {{label}}\nRequirement from the shop: {{rule}}\nImage size: {{width}}x{{height}} px",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'fit'     => array( 'type' => 'BOOLEAN' ),
						'score'   => array(
							'type'    => 'INTEGER',
							'minimum' => 0,
							'maximum' => 100,
						),
						'issues'  => array(
							'type'     => 'ARRAY',
							'maxItems' => 5,
							'items'    => array(
								'type'      => 'STRING',
								'maxLength' => 120,
							),
						),
						'message' => array(
							'type'      => 'STRING',
							'maxLength' => 160,
						),
					),
					'required'   => array( 'fit', 'score', 'message' ),
				),
				'temperature' => 0.1,
				'profile'     => 'fast',
				'cache'       => MONTH_IN_SECONDS,
			)
		);
	}

	/**
	 * Schema for generated option sets.
	 *
	 * @return array
	 */
	public static function generate_schema() {
		$str = static fn( $max ) => array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'fields' => array(
					'type'     => 'ARRAY',
					'maxItems' => 16,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'key'         => $str( 30 ),
							'type'        => array(
								'type' => 'STRING',
								'enum' => self::GENERATED_TYPES,
							),
							'label'       => $str( 80 ),
							'help'        => $str( 200 ),
							'tooltip'     => $str( 200 ),
							'placeholder' => $str( 80 ),
							'required'    => array( 'type' => 'BOOLEAN' ),
							'price_type'  => array(
								'type' => 'STRING',
								'enum' => array( 'none', 'flat', 'percent', 'per_char', 'per_unit', 'formula' ),
							),
							'price'       => array( 'type' => 'NUMBER' ),
							'formula'     => $str( 200 ),
							'charge'      => array(
								'type' => 'STRING',
								'enum' => array( 'item', 'once' ),
							),
							'min'         => array( 'type' => 'NUMBER' ),
							'max'         => array( 'type' => 'NUMBER' ),
							'maxlength'   => array( 'type' => 'INTEGER' ),
							'free_chars'  => array( 'type' => 'INTEGER' ),
							'unit'        => $str( 12 ),
							'options'     => array(
								'type'     => 'ARRAY',
								'maxItems' => 12,
								'items'    => array(
									'type'       => 'OBJECT',
									'properties' => array(
										'label'      => $str( 80 ),
										'price'      => array( 'type' => 'NUMBER' ),
										'price_type' => array(
											'type' => 'STRING',
											'enum' => array( 'flat', 'percent' ),
										),
										'color'      => $str( 7 ),
										'default'    => array( 'type' => 'BOOLEAN' ),
									),
									'required'   => array( 'label', 'price' ),
								),
							),
							'condition'   => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'action' => array(
										'type' => 'STRING',
										'enum' => array( 'show', 'hide' ),
									),
									'match'  => array(
										'type' => 'STRING',
										'enum' => array( 'all', 'any' ),
									),
									'rules'  => array(
										'type'     => 'ARRAY',
										'maxItems' => 4,
										'items'    => array(
											'type'       => 'OBJECT',
											'properties' => array(
												'field'    => $str( 30 ),
												'operator' => array(
													'type' => 'STRING',
													'enum' => Field_Types::OPERATORS,
												),
												'value'    => $str( 80 ),
											),
											'required'   => array( 'field', 'operator' ),
										),
									),
								),
							),
						),
						'required'   => array( 'key', 'type', 'label', 'required', 'price_type' ),
					),
				),
				'notes'  => $str( 400 ),
			),
			'required'   => array( 'fields' ),
		);
	}

	/**
	 * Schema for label/translation drafts.
	 *
	 * @return array
	 */
	public static function labels_schema() {
		$str = static fn( $max ) => array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'fields' => array(
					'type'     => 'ARRAY',
					'maxItems' => 100,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'id'          => $str( 32 ),
							'label'       => $str( 120 ),
							'help'        => $str( 300 ),
							'tooltip'     => $str( 300 ),
							'placeholder' => $str( 120 ),
							'options'     => array(
								'type'     => 'ARRAY',
								'maxItems' => 60,
								'items'    => array(
									'type'       => 'OBJECT',
									'properties' => array(
										'id'    => $str( 32 ),
										'label' => $str( 120 ),
									),
									'required'   => array( 'id', 'label' ),
								),
							),
						),
						'required'   => array( 'id', 'label' ),
					),
				),
			),
			'required'   => array( 'fields' ),
		);
	}
}
