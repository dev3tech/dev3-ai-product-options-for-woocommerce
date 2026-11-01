<?php
/**
 * Storefront HTML for option fields (progressively enhanced by src/storefront/index.js).
 *
 * @package Dev3\AiProductOptions
 */

namespace Dev3\AiProductOptions\Frontend;

use Dev3\AiProductOptions\Core3\Core\Settings_Store;
use Dev3\AiProductOptions\Fields\Field_Schema;
use Dev3\AiProductOptions\Fields\Field_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a form block. All shopper-facing text is neutral (no plugin branding).
 */
class Renderer {

	/**
	 * Unique prefix for ids inside one form.
	 *
	 * @var string
	 */
	private $uid = '';

	/**
	 * Prefill values (id => parsed value).
	 *
	 * @var array
	 */
	private $values = array();

	/**
	 * Base unit price.
	 *
	 * @var float
	 */
	private $base = 0.0;

	/**
	 * Form instances on this page.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Renders the full form block.
	 *
	 * @param array $fields     Localised fields.
	 * @param int   $product_id Product id.
	 * @param float $base       Product unit price.
	 * @param array $values     Prefill values.
	 * @param array $extra      Extra config (edit key…).
	 * @return string
	 */
	public function render( array $fields, $product_id, $base, array $values = array(), array $extra = array() ) {
		if ( ! $fields ) {
			return '';
		}
		++self::$count;
		$this->uid    = 'dev3po' . self::$count . '-' . (int) $product_id;
		$this->values = $values;
		$this->base   = (float) $base;
		$has_steps    = (bool) array_filter( $fields, static fn( $f ) => 'step' === $f['type'] );
		$config       = array_merge( $this->config( $fields, $product_id ), $extra );

		$html  = '<div class="dev3po-form' . ( $has_steps ? ' dev3po-form--steps' : '' ) . '" id="' . esc_attr( $this->uid ) . '" data-dev3po="' . esc_attr( wp_json_encode( $config ) ) . '">';
		$html .= '<input type="hidden" name="dev3po_present" value="1">';
		if ( ! empty( $extra['edit'] ) ) {
			$html .= '<input type="hidden" name="dev3po_edit" value="' . esc_attr( $extra['edit'] ) . '">';
		}
		$html .= '<div class="dev3po-fields">' . $this->render_groups( $fields ) . '</div>';
		if ( $has_steps ) {
			$html .= '<div class="dev3po-step-controls" hidden><button type="button" class="dev3po-btn dev3po-step-prev">' . esc_html__( 'Back', 'dev3-ai-product-options-for-woocommerce' ) . '</button><span class="dev3po-step-count" aria-live="polite"></span><button type="button" class="dev3po-btn dev3po-btn--primary dev3po-step-next">' . esc_html__( 'Next', 'dev3-ai-product-options-for-woocommerce' ) . '</button></div>';
		}
		if ( Settings_Store::get( 'show_totals', true ) ) {
			$html .= '<div class="dev3po-totals" aria-live="polite" hidden>';
			$html .= '<div class="dev3po-totals__row"><span>' . esc_html( (string) Settings_Store::get( 'label_options_total', __( 'Options total', 'dev3-ai-product-options-for-woocommerce' ) ) ) . '</span><span class="dev3po-totals__value" data-total="options"></span></div>';
			$html .= '<div class="dev3po-totals__row dev3po-totals__fees" hidden><span>' . esc_html__( 'One-time fees', 'dev3-ai-product-options-for-woocommerce' ) . '</span><span class="dev3po-totals__value" data-total="once"></span></div>';
			$html .= '<div class="dev3po-totals__row dev3po-totals__grand"><span>' . esc_html( (string) Settings_Store::get( 'label_grand_total', __( 'Total', 'dev3-ai-product-options-for-woocommerce' ) ) ) . '</span><span class="dev3po-totals__value" data-total="grand"></span></div>';
			$html .= '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Groups fields into steps and sections.
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	private function render_groups( array $fields ) {
		$html         = '';
		$open_step    = false;
		$open_section = '';
		foreach ( $fields as $f ) {
			if ( 'step' === $f['type'] ) {
				$html        .= $this->close_section( $open_section );
				$open_section = '';
				if ( $open_step ) {
					$html .= '</div></div>';
				}
				$open_step = true;
				$html     .= '<div class="dev3po-step" data-field="' . esc_attr( $f['id'] ) . '" role="group" aria-labelledby="' . esc_attr( $this->id( $f['id'] ) ) . '-title">';
				$html     .= '<h3 class="dev3po-step__title" id="' . esc_attr( $this->id( $f['id'] ) ) . '-title">' . esc_html( $f['label'] ) . '</h3>';
				if ( '' !== $f['description'] ) {
					$html .= '<div class="dev3po-step__desc">' . wp_kses_post( $f['description'] ) . '</div>';
				}
				$html .= '<div class="dev3po-step__body">';
				continue;
			}
			if ( 'section' === $f['type'] ) {
				$html        .= $this->close_section( $open_section );
				$open_section = $f['collapsible'] ? 'details' : 'fieldset';
				if ( $f['collapsible'] ) {
					$html .= '<details class="dev3po-section dev3po-section--collapsible" data-field="' . esc_attr( $f['id'] ) . '" open><summary class="dev3po-section__title">' . esc_html( $f['label'] ) . '</summary>';
				} else {
					$html .= '<fieldset class="dev3po-section" data-field="' . esc_attr( $f['id'] ) . '"><legend class="dev3po-section__title">' . esc_html( $f['label'] ) . '</legend>';
				}
				if ( '' !== $f['description'] ) {
					$html .= '<div class="dev3po-section__desc">' . wp_kses_post( $f['description'] ) . '</div>';
				}
				$html .= '<div class="dev3po-section__body">';
				continue;
			}
			$html .= $this->field( $f, $this->values[ $f['id'] ] ?? null );
		}
		$html .= $this->close_section( $open_section );
		if ( $open_step ) {
			$html .= '</div></div>';
		}
		return $html;
	}

	/**
	 * Closes a section.
	 *
	 * @param string $tag Open tag or ''.
	 * @return string
	 */
	private function close_section( $tag ) {
		return '' === $tag ? '' : '</div></' . $tag . '>';
	}

	/**
	 * Element id.
	 *
	 * @param string $suffix Suffix.
	 * @return string
	 */
	private function id( $suffix ) {
		return $this->uid . '-' . $suffix;
	}

	/**
	 * One field.
	 *
	 * @param array  $f     Field.
	 * @param mixed  $value Prefill.
	 * @param string $name  Input name override (repeater rows).
	 * @param string $idsfx Id suffix override.
	 * @return string
	 */
	public function field( array $f, $value = null, $name = '', $idsfx = '' ) {
		$type  = $f['type'];
		$kind  = Field_Types::kind( $type );
		$name  = '' !== $name ? $name : 'dev3po[' . $f['id'] . ']';
		$id    = $this->id( '' !== $idsfx ? $idsfx : $f['id'] );
		$value = null === $value ? $this->default_value( $f ) : $value;

		if ( 'paragraph' === $type ) {
			return '<div class="dev3po-field dev3po-paragraph" data-field="' . esc_attr( $f['id'] ) . '" data-type="paragraph">' . ( '' !== $f['label'] ? '<p class="dev3po-paragraph__title">' . esc_html( $f['label'] ) . '</p>' : '' ) . wp_kses_post( wpautop( $f['description'] ) ) . '</div>';
		}
		if ( 'hidden' === $type ) {
			return '<div class="dev3po-field dev3po-field--hidden" data-field="' . esc_attr( $f['id'] ) . '" data-type="hidden" hidden><input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $f['default'] ) . '"></div>';
		}

		$classes = array( 'dev3po-field', 'dev3po-field--' . $type );
		if ( 'half' === $f['width'] ) {
			$classes[] = 'dev3po-field--half';
		}
		if ( ! empty( $f['class'] ) ) {
			$classes[] = $f['class'];
		}
		$describedby = array();
		if ( '' !== $f['help'] ) {
			$describedby[] = $id . '-help';
		}
		$describedby[] = $id . '-error';
		$aria          = ' aria-describedby="' . esc_attr( implode( ' ', $describedby ) ) . '"' . ( $f['required'] ? ' aria-required="true"' : '' );

		$grouped = in_array( $kind, array( 'multi' ), true ) || in_array( $type, array( 'radio', 'color_swatch', 'image_swatch', 'image_picker' ), true );
		$html    = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-field="' . esc_attr( $f['id'] ) . '" data-type="' . esc_attr( $type ) . '">';
		$label   = esc_html( $f['label'] ) . ( $f['required'] ? ' <span class="dev3po-req" aria-hidden="true">*</span>' : '' ) . $this->price_hint( $f );
		$tip     = $this->tooltip( $f, $id );

		if ( $grouped ) {
			$html .= '<fieldset class="dev3po-group"' . $aria . '><legend class="dev3po-label">' . $label . $tip . '</legend>';
			$html .= $this->choices( $f, $value, $name, $id );
			$html .= '</fieldset>';
		} elseif ( 'bool' === $kind ) {
			$html .= '<label class="dev3po-check' . ( 'toggle' === $type ? ' dev3po-check--toggle' : '' ) . '" for="' . esc_attr( $id ) . '">';
			$html .= '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . $aria . ( 'toggle' === $type ? ' role="switch"' : '' ) . '>';
			$html .= ( 'toggle' === $type ? '<span class="dev3po-switch" aria-hidden="true"></span>' : '' ) . '<span class="dev3po-check__label">' . $label . '</span></label>' . $tip;
			$html  = str_replace( '<label class="dev3po-check', '<div class="dev3po-head"><label class="dev3po-check', $html ) . '</div>';
		} elseif ( 'repeater' === $type ) {
			$html .= '<div class="dev3po-label" id="' . esc_attr( $id ) . '-label">' . $label . $tip . '</div>';
			$html .= $this->repeater( $f, (array) $value, $name, $id );
		} else {
			$html .= '<div class="dev3po-head"><label class="dev3po-label" for="' . esc_attr( $id ) . '">' . $label . '</label>' . $tip . '</div>';
			$html .= $this->control( $f, $value, $name, $id, $aria );
		}
		if ( '' !== $f['help'] ) {
			$html .= '<p class="dev3po-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $f['help'] ) . '</p>';
		}
		$html .= '<p class="dev3po-error" id="' . esc_attr( $id ) . '-error" role="alert" hidden></p>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Default value from field settings.
	 *
	 * @param array $f Field.
	 * @return mixed
	 */
	private function default_value( array $f ) {
		$kind = Field_Types::kind( $f['type'] );
		if ( 'choice' === $kind ) {
			foreach ( $f['options'] as $opt ) {
				if ( ! empty( $opt['default'] ) ) {
					return $opt['id'];
				}
			}
			return '';
		}
		if ( 'multi' === $kind ) {
			return array_values( wp_list_pluck( array_filter( $f['options'], static fn( $o ) => ! empty( $o['default'] ) ), 'id' ) );
		}
		if ( 'bool' === $kind ) {
			return in_array( strtolower( (string) $f['default'] ), array( '1', 'yes', 'true', 'on' ), true );
		}
		if ( 'repeater' === $kind ) {
			return array_fill( 0, max( 1, (int) $f['min_rows'] ), array() );
		}
		if ( 'file' === $kind ) {
			return array();
		}
		return $f['default'] ?? '';
	}

	/**
	 * Input controls.
	 *
	 * @param array  $f     Field.
	 * @param mixed  $value Value.
	 * @param string $name  Name.
	 * @param string $id    Id.
	 * @param string $aria  Aria attributes.
	 * @return string
	 */
	private function control( array $f, $value, $name, $id, $aria ) {
		$type   = $f['type'];
		$common = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . ( $f['required'] ? ' required' : '' );
		$ph     = '' !== $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';
		switch ( $type ) {
			case 'textarea':
				$max = ! empty( $f['maxlength'] ) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '';
				return '<textarea class="dev3po-input" rows="3"' . $common . $ph . $max . '>' . esc_textarea( (string) $value ) . '</textarea>' . ( $max ? '<span class="dev3po-counter" aria-hidden="true"></span>' : '' );
			case 'text':
			case 'email':
			case 'url':
			case 'tel':
				$max = ! empty( $f['maxlength'] ) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '';
				$ac  = 'email' === $type ? ' autocomplete="email"' : ( 'tel' === $type ? ' autocomplete="tel"' : '' );
				return '<input class="dev3po-input" type="' . esc_attr( $type ) . '"' . $common . $ph . $max . $ac . ' value="' . esc_attr( (string) $value ) . '">' . ( $max && 'text' === $type ? '<span class="dev3po-counter" aria-hidden="true"></span>' : '' );
			case 'number':
				$attrs = $this->num_attrs( $f );
				$input = '<input class="dev3po-input" type="number" inputmode="decimal"' . $common . $ph . $attrs . ' value="' . esc_attr( null === $value || '' === $value ? '' : (string) $value ) . '">';
				return '' !== ( $f['unit'] ?? '' ) ? '<span class="dev3po-unit-wrap">' . $input . '<span class="dev3po-unit">' . esc_html( $f['unit'] ) . '</span></span>' : $input;
			case 'range':
				$v = ( null === $value || '' === $value ) ? $f['min'] : (float) $value;
				return '<div class="dev3po-range"><input type="range"' . $common . $this->num_attrs( $f ) . ' value="' . esc_attr( (string) $v ) . '"><output class="dev3po-range__out" for="' . esc_attr( $id ) . '">' . esc_html( $v . ( '' !== $f['unit'] ? ' ' . $f['unit'] : '' ) ) . '</output></div>';
			case 'select':
				$html = '<select class="dev3po-input"' . $common . '><option value="">' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : __( 'Choose an option', 'dev3-ai-product-options-for-woocommerce' ) ) . '</option>';
				foreach ( $f['options'] as $opt ) {
					$html .= '<option value="' . esc_attr( $opt['id'] ) . '"' . selected( $value, $opt['id'], false ) . '>' . esc_html( $opt['label'] . $this->option_hint( $opt, true ) ) . '</option>';
				}
				return $html . '</select>';
			case 'date':
			case 'datetime':
			case 'time':
				$input_type = 'datetime' === $type ? 'datetime-local' : $type;
				$attrs      = '';
				if ( 'time' !== $type ) {
					$today = current_time( 'Y-m-d' );
					if ( null !== $f['date_min'] ) {
						$attrs .= ' min="' . esc_attr( gmdate( 'Y-m-d', strtotime( $today . ' ' . (int) $f['date_min'] . ' days' ) ) . ( 'datetime' === $type ? 'T00:00' : '' ) ) . '"';
					}
					if ( null !== $f['date_max'] ) {
						$attrs .= ' max="' . esc_attr( gmdate( 'Y-m-d', strtotime( $today . ' ' . (int) $f['date_max'] . ' days' ) ) . ( 'datetime' === $type ? 'T23:59' : '' ) ) . '"';
					}
					if ( ! empty( $f['disabled_days'] ) ) {
						$attrs .= ' data-disabled-days="' . esc_attr( implode( ',', $f['disabled_days'] ) ) . '"';
					}
				}
				return '<input class="dev3po-input" type="' . esc_attr( $input_type ) . '"' . $common . $attrs . ' value="' . esc_attr( (string) $value ) . '">';
			case 'color':
				$v = '' !== (string) $value ? (string) $value : '#000000';
				return '<span class="dev3po-color"><input type="color"' . $common . ' value="' . esc_attr( $v ) . '"><span class="dev3po-color__value" aria-hidden="true">' . esc_html( $v ) . '</span></span>';
			case 'file':
				$accept = implode( ',', array_map( static fn( $e ) => '.' . $e, explode( ',', $f['accept'] ) ) );
				$tokens = implode( ',', wp_list_pluck( (array) $value, 'token' ) );
				$html   = '<div class="dev3po-upload" data-max-files="' . (int) $f['max_files'] . '" data-max-size="' . esc_attr( (string) $f['max_size'] ) . '">';
				$html  .= '<input class="dev3po-upload__input dev3po-sr" type="file" id="' . esc_attr( $id ) . '" accept="' . esc_attr( $accept ) . '"' . ( $f['max_files'] > 1 ? ' multiple' : '' ) . $aria . '>';
				$html  .= '<input type="hidden" class="dev3po-upload__tokens" name="' . esc_attr( $name ) . '" value="' . esc_attr( $tokens ) . '">';
				$html  .= '<label class="dev3po-upload__drop" for="' . esc_attr( $id ) . '"><span class="dev3po-upload__icon" aria-hidden="true"></span><span class="dev3po-upload__cta">' . esc_html__( 'Choose a file or drop it here', 'dev3-ai-product-options-for-woocommerce' ) . '</span><small>' . esc_html(
					sprintf(
						/* translators: 1: extensions, 2: size in MB */
						__( '%1$s · up to %2$s MB', 'dev3-ai-product-options-for-woocommerce' ),
						strtoupper( str_replace( ',', ', ', $f['accept'] ) ),
						$f['max_size']
					)
				) . '</small></label>';
				$html .= '<ul class="dev3po-upload__list">';
				foreach ( (array) $value as $file ) {
					$html .= '<li class="dev3po-upload__item is-done" data-token="' . esc_attr( $file['token'] ) . '"><span class="dev3po-upload__name">' . esc_html( $file['name'] ) . '</span><button type="button" class="dev3po-upload__remove" aria-label="' . esc_attr__( 'Remove file', 'dev3-ai-product-options-for-woocommerce' ) . '">&times;</button></li>';
				}
				return $html . '</ul></div>';
		}
		return '';
	}

	/**
	 * Number attributes.
	 *
	 * @param array $f Field.
	 * @return string
	 */
	private function num_attrs( array $f ) {
		$out = '';
		foreach ( array( 'min', 'max', 'step' ) as $k ) {
			if ( isset( $f[ $k ] ) && null !== $f[ $k ] ) {
				$out .= ' ' . $k . '="' . esc_attr( (string) $f[ $k ] ) . '"';
			}
		}
		if ( ! isset( $f['step'] ) || null === $f['step'] ) {
			$out .= ' step="any"';
		}
		return $out;
	}

	/**
	 * Radio / checkbox / swatch / image choices.
	 *
	 * @param array  $f     Field.
	 * @param mixed  $value Value.
	 * @param string $name  Name.
	 * @param string $id    Id.
	 * @return string
	 */
	private function choices( array $f, $value, $name, $id ) {
		$multi = 'checkboxes' === $f['type'];
		$type  = $multi ? 'checkbox' : 'radio';
		$style = array(
			'radio'        => 'list',
			'checkboxes'   => 'list',
			'color_swatch' => 'swatch',
			'image_swatch' => 'swatch',
			'image_picker' => 'cards',
		)[ $f['type'] ];
		$html  = '<div class="dev3po-choices dev3po-choices--' . esc_attr( $style ) . '"' . ( $multi && $f['max_select'] ? ' data-max="' . (int) $f['max_select'] . '"' : '' ) . '>';
		foreach ( $f['options'] as $i => $opt ) {
			$oid     = $id . '-' . $i;
			$checked = $multi ? in_array( $opt['id'], (array) $value, true ) : $value === $opt['id'];
			$input   = '<input type="' . $type . '" class="' . ( 'list' === $style ? 'dev3po-choice__input' : 'dev3po-sr' ) . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '" value="' . esc_attr( $opt['id'] ) . '"' . checked( $checked, true, false ) . ( ! $multi && $f['required'] && 0 === $i ? ' required' : '' ) . '>';
			$hint    = $this->option_hint( $opt );
			$hint    = '' !== $hint ? '<span class="dev3po-price">' . esc_html( $hint ) . '</span>' : '';
			if ( 'list' === $style ) {
				$html .= '<label class="dev3po-choice" for="' . esc_attr( $oid ) . '">' . $input . '<span class="dev3po-choice__label">' . esc_html( $opt['label'] ) . '</span>' . $hint . '</label>';
			} elseif ( 'swatch' === $style ) {
				$chip  = 'image_swatch' === $f['type'] && '' !== $opt['image']
					? '<img class="dev3po-swatch__img" src="' . esc_url( $opt['image'] ) . '" alt="" width="40" height="40" loading="lazy" decoding="async">'
					: '<span class="dev3po-swatch__color" style="--dev3po-chip:' . esc_attr( '' !== $opt['color'] ? $opt['color'] : '#cccccc' ) . '"></span>';
				$html .= '<label class="dev3po-swatch" for="' . esc_attr( $oid ) . '" title="' . esc_attr( $opt['label'] ) . '">' . $input . '<span class="dev3po-swatch__chip">' . $chip . '</span><span class="dev3po-swatch__label">' . esc_html( $opt['label'] ) . '</span>' . $hint . '</label>';
			} else {
				$img   = '' !== $opt['image'] ? '<img src="' . esc_url( $opt['image'] ) . '" alt="" width="160" height="120" loading="lazy" decoding="async">' : '<span class="dev3po-card__placeholder"></span>';
				$html .= '<label class="dev3po-card" for="' . esc_attr( $oid ) . '">' . $input . '<span class="dev3po-card__media">' . $img . '</span><span class="dev3po-card__label">' . esc_html( $opt['label'] ) . '</span>' . $hint . '</label>';
			}
		}
		return $html . '</div>';
	}

	/**
	 * Repeater rows + template.
	 *
	 * @param array  $f     Field.
	 * @param array  $rows  Rows.
	 * @param string $name  Name.
	 * @param string $id    Id.
	 * @return string
	 */
	private function repeater( array $f, array $rows, $name, $id ) {
		if ( ! $rows ) {
			$rows = array_fill( 0, max( 1, (int) $f['min_rows'] ), array() );
		}
		$html = '<div class="dev3po-repeater" data-min="' . (int) $f['min_rows'] . '" data-max="' . (int) $f['max_rows'] . '" role="group" aria-labelledby="' . esc_attr( $id ) . '-label"><div class="dev3po-repeater__rows">';
		foreach ( array_values( $rows ) as $i => $row ) {
			$html .= $this->repeater_row( $f, (array) $row, $name, (string) $i );
		}
		$html .= '</div><template class="dev3po-repeater__tpl">' . $this->repeater_row( $f, array(), $name, '__ROW__' ) . '</template>';
		$html .= '<button type="button" class="dev3po-btn dev3po-repeater__add">+ ' . esc_html( '' !== $f['button_label'] ? $f['button_label'] : __( 'Add another', 'dev3-ai-product-options-for-woocommerce' ) ) . '</button></div>';
		return $html;
	}

	/**
	 * One repeater row.
	 *
	 * @param array  $f    Field.
	 * @param array  $row  Values.
	 * @param string $name Base name.
	 * @param string $i    Row index or __ROW__.
	 * @return string
	 */
	private function repeater_row( array $f, array $row, $name, $i ) {
		$num   = '__ROW__' === $i ? '__NUM__' : (string) ( (int) $i + 1 );
		$html  = '<div class="dev3po-repeater__row" data-row="' . esc_attr( $i ) . '"><div class="dev3po-repeater__head"><span class="dev3po-repeater__num">#' . esc_html( $num ) . '</span>';
		$html .= '<button type="button" class="dev3po-repeater__remove" aria-label="' . esc_attr__( 'Remove entry', 'dev3-ai-product-options-for-woocommerce' ) . '">&times;</button></div><div class="dev3po-repeater__fields">';
		foreach ( $f['subfields'] as $sub ) {
			$html .= $this->field( $sub, $row[ $sub['id'] ] ?? null, $name . '[' . $i . '][' . $sub['id'] . ']', $f['id'] . '-' . $i . '-' . $sub['id'] );
		}
		return $html . '</div></div>';
	}

	/**
	 * Tooltip markup (CSS shows it on hover/focus).
	 *
	 * @param array  $f  Field.
	 * @param string $id Id.
	 * @return string
	 */
	private function tooltip( array $f, $id ) {
		if ( '' === $f['tooltip'] ) {
			return '';
		}
		return '<span class="dev3po-tip"><button type="button" class="dev3po-tip__btn" aria-describedby="' . esc_attr( $id ) . '-tip" aria-label="' . esc_attr__( 'More information', 'dev3-ai-product-options-for-woocommerce' ) . '">?</button><span class="dev3po-tip__text" role="tooltip" id="' . esc_attr( $id ) . '-tip">' . esc_html( $f['tooltip'] ) . '</span></span>';
	}

	/**
	 * Short price hint for a field label.
	 *
	 * @param array $f Field.
	 * @return string HTML.
	 */
	private function price_hint( array $f ) {
		$p    = (float) $f['price'];
		$text = '';
		switch ( $f['price_type'] ) {
			case 'flat':
				$text = 'repeater' === $f['type']
					/* translators: %s: price */
					? sprintf( __( '%s each', 'dev3-ai-product-options-for-woocommerce' ), self::signed( $p ) )
					: self::signed( $p );
				break;
			case 'percent':
				$text = ( $p >= 0 ? '+' : '−' ) . wc_format_localized_decimal( (string) abs( $p ) ) . '%';
				break;
			case 'per_char':
				$text = $f['free_chars']
					/* translators: 1: price, 2: number of free characters */
					? sprintf( __( '%1$s per character after %2$d', 'dev3-ai-product-options-for-woocommerce' ), self::signed( $p ), $f['free_chars'] )
					/* translators: %s: price */
					: sprintf( __( '%s per character', 'dev3-ai-product-options-for-woocommerce' ), self::signed( $p ) );
				break;
			case 'per_unit':
				/* translators: %s: price */
				$text = sprintf( __( '%s each', 'dev3-ai-product-options-for-woocommerce' ), self::signed( $p ) );
				break;
		}
		if ( '' !== $text && 'once' === $f['charge'] ) {
			/* translators: %s: price */
			$text = sprintf( __( '%s one-time', 'dev3-ai-product-options-for-woocommerce' ), $text );
		}
		return '' === $text || 0.0 === $p ? '' : ' <span class="dev3po-price">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Price hint for an option.
	 *
	 * @param array $opt    Option.
	 * @param bool  $parens Wrap in parentheses (select options).
	 * @return string Plain text.
	 */
	private function option_hint( array $opt, $parens = false ) {
		if ( 0.0 === (float) $opt['price'] ) {
			return '';
		}
		$text = 'percent' === $opt['price_type'] ? ( $opt['price'] >= 0 ? '+' : '−' ) . wc_format_localized_decimal( (string) abs( $opt['price'] ) ) . '%' : self::signed( (float) $opt['price'] );
		return $parens ? ' (' . $text . ')' : $text;
	}

	/**
	 * "+$2.00" / "−$1.00".
	 *
	 * @param float $p Price.
	 * @return string
	 */
	public static function signed( $p ) {
		return ( $p >= 0 ? '+' : '−' ) . html_entity_decode( wp_strip_all_tags( wc_price( abs( $p ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Script config.
	 *
	 * @param array $fields     Fields.
	 * @param int   $product_id Product.
	 * @return array
	 */
	private function config( array $fields, $product_id ) {
		return array(
			'product'  => (int) $product_id,
			'base'     => $this->base,
			'fields'   => Field_Schema::public_fields( $fields ),
			'currency' => self::currency(),
			'upload'   => esc_url_raw( rest_url( 'dev3po/v1/upload' ) ),
		);
	}

	/**
	 * Currency format.
	 *
	 * @return array
	 */
	public static function currency() {
		return array(
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'format'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
			'decimals' => wc_get_price_decimals(),
			'dsep'     => wc_get_price_decimal_separator(),
			'tsep'     => wc_get_price_thousand_separator(),
		);
	}
}
