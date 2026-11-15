<?php
namespace IFG;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Utils;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Image_Size;
use Elementor\Group_Control_Css_Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget: Infinite Scroll Gallery.
 * NOTE: the control list below is generated from the same schema that drives the live demo.
 */
class Gallery_Widget extends Widget_Base {

	/**
	 * One-time upgrade. Widgets saved before the default changed have no displayMode key, so without this they
	 * would silently jump from "contained" to "page". Their effective mode is written into their saved data.
	 */
	const MODE_MIGRATION_OPTION = 'ifg_display_mode_migrated';

	public static function display_mode_migrated() {
		return (bool) get_option( self::MODE_MIGRATION_OPTION );
	}

	public static function migrate_display_mode() {
		global $wpdb;
		if ( self::display_mode_migrated() ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
			'_elementor_data',
			'%ifg_gallery%'
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $ids as $post_id ) {
			$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$changed = false;
			$data    = self::stamp_legacy_mode( $data, $changed );
			if ( $changed ) {
				update_post_meta( $post_id, '_elementor_data', wp_json_encode( $data ) );
			}
		}
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		update_option( self::MODE_MIGRATION_OPTION, 1 );
	}

	private static function stamp_legacy_mode( $nodes, &$changed ) {
		foreach ( $nodes as $k => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['widgetType'] ) && 'ifg_gallery' === $node['widgetType'] ) {
				$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
				if ( ! array_key_exists( 'displayMode', $settings ) ) {
					$settings['displayMode'] = self::legacy_display_mode( $settings );
					$node['settings']        = $settings;
					$changed                 = true;
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = self::stamp_legacy_mode( $node['elements'], $changed );
			}
			$nodes[ $k ] = $node;
		}
		return $nodes;
	}

	/** Same precedence as resolve_display_mode() for widgets saved before the single display option. */
	private static function legacy_display_mode( $raw ) {
		if ( 'yes' === ( $raw['pageMode'] ?? '' ) ) {
			return 'page';
		}
		if ( 'fixed' === ( $raw['overlay'] ?? '' ) ) {
			return 'overlay';
		}
		if ( 'yes' === ( $raw['fullBleed'] ?? '' ) ) {
			return 'bleed';
		}
		return 'contained';
	}

	public function get_name() {
		return 'ifg_gallery';
	}

	public function get_title() {
		return esc_html__( 'گالری اسکرول بی‌نهایت', 'ifg' );
	}

	public function get_icon() {
		return 'eicon-gallery-justified';
	}

	public function get_categories() {
		return [ 'ifg', 'general' ];
	}

	public function get_keywords() {
		return [ 'gallery', 'infinite', 'scroll', 'gsap', 'flip', 'parallax', 'portfolio', 'slider', 'گالری', 'اسکرول', 'اسلایدر', 'پارالاکس' ];
	}

	public function get_script_depends() {
		return [ 'ifg-script' ];
	}

	public function get_style_depends() {
		return [ 'ifg-style' ];
	}

	/**
	 * Tell Elementor's element cache that "Posts" mode must not be cached (the output changes with the content).
	 *
	 * Elementor calls this during editor bootstrap, where the widget is instantiated
	 * WITHOUT element data. Calling get_settings() at that point triggers a fatal error
	 * inside sanitize_settings() (null given). Bail out early when there is no element id.
	 */
	protected function is_dynamic_content(): bool {
		if ( ! $this->get_id() ) {
			return false;
		}

		return in_array( $this->get_settings( 'source' ) ?: 'manual', [ 'product', 'posts' ], true );
	}

	/**
	 * Whitelist for the user-selectable HTML tags.
	 */
	private function safe_tag( $tag, $fallback ) {
		$allowed = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'p', 'span' ];
		$tag     = is_string( $tag ) ? strtolower( $tag ) : '';
		return in_array( $tag, $allowed, true ) ? $tag : $fallback;
	}

	private function tag_options() {
		return [
			'h1'   => 'H1',
			'h2'   => 'H2',
			'h3'   => 'H3',
			'h4'   => 'H4',
			'h5'   => 'H5',
			'h6'   => 'H6',
			'div'  => 'div',
			'p'    => 'p',
			'span' => 'span',
		];
	}

	/**
	 * Terms of every public taxonomy as "taxonomy|id" => "Taxonomy: Term" (for the term picker).
	 */
	private function term_options() {
		$opts = [];
		foreach ( [ 'product_cat' => esc_html__( 'دسته‌بندی محصول', 'ifg' ), 'product_tag' => esc_html__( 'برچسب محصول', 'ifg' ) ] as $slug => $label ) {
			if ( ! taxonomy_exists( $slug ) ) {
				continue;
			}
			$terms = get_terms( [ 'taxonomy' => $slug, 'hide_empty' => false, 'number' => 300, 'orderby' => 'name' ] );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$opts[ $slug . '|' . $term->term_id ] = $label . ': ' . $term->name;
			}
		}
		return $opts;
	}

	/**
	 * Product categories as term_id => name (for the filter-bar category picker).
	 */
	private function category_options() {
		$opts = [];
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return $opts;
		}
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 300, 'orderby' => 'name' ] );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $opts;
		}
		foreach ( $terms as $term ) {
			$opts[ $term->term_id ] = $term->name;
		}
		return $opts;
	}

	/**
	 * Request context used when the slides are rendered by the AJAX category filter
	 * (there is no "current page" in admin-ajax.php, so the page sends it along).
	 */
	private $ctx_current = null; // int|null: ID of the page the visitor is on (for "exclude current product")
	private $force_term  = 0;    // int: product_cat term chosen in the filter bar (0 = none)

	protected function register_controls() {

		/* ---- Slides ---- */
		$this->start_controls_section( 'section_source', [
			'label' => esc_html__( 'اسلایدها', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'source', [
			'label'   => esc_html__( 'منبع', 'ifg' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'manual',
			'options' => [
				'manual'  => esc_html__( 'دستی (لیست تکرارشونده)', 'ifg' ),
				'product' => esc_html__( 'محصولات ووکامرس', 'ifg' ),
			],
		] );

		$repeater = new Repeater();

		$repeater->add_control( 'media_type', [
			'label'   => esc_html__( 'نوع رسانه', 'ifg' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'image',
			'options' => [
				'image' => esc_html__( 'تصویر', 'ifg' ),
				'video' => esc_html__( 'ویدیو (MP4 / YouTube / Vimeo)', 'ifg' ),
			],
		] );

		$repeater->add_control( 'image', [
			'label'       => esc_html__( 'تصویر (برای ویدیو: پوستر / بندانگشتی)', 'ifg' ),
			'description' => esc_html__( 'در اسلاید ویدیویی، این تصویر بندانگشتی و پوستر است و انیمیشن Flip روی آن اجرا می‌شود. برای YouTube و Vimeo اگر تصویر را عوض نکنید، پوستر خودکار گرفته می‌شود.', 'ifg' ),
			'type'        => Controls_Manager::MEDIA,
			'default'     => [ 'url' => Utils::get_placeholder_image_src() ],
			'dynamic'     => [ 'active' => true ],
		] );

		$repeater->add_control( 'video_url', [
			'label'       => esc_html__( 'آدرس ویدیو', 'ifg' ),
			'description' => esc_html__( 'لینک YouTube، Vimeo یا یک فایل MP4/WebM. نوع به‌صورت خودکار تشخیص داده می‌شود.', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'label_block' => true,
			'placeholder' => 'https://www.youtube.com/watch?v=…',
			'dynamic'     => [ 'active' => true ],
			'condition'   => [ 'media_type' => 'video' ],
		] );

		$repeater->add_control( 'video_file', [
			'label'       => esc_html__( 'یا فایل ویدیو از کتابخانه رسانه', 'ifg' ),
			'description' => esc_html__( 'اگر انتخاب شود بر آدرس بالا اولویت دارد.', 'ifg' ),
			'type'        => Controls_Manager::MEDIA,
			'media_types' => [ 'video' ],
			'condition'   => [ 'media_type' => 'video' ],
		] );

		$repeater->add_control( 'video_autoplay', [
			'label'        => esc_html__( 'پخش خودکار بعد از باز شدن', 'ifg' ),
			'description'  => esc_html__( 'مرورگرها پخش خودکار را فقط بی‌صدا اجازه می‌دهند. اگر بازدیدکننده «کاهش حرکت» را در سیستم‌عامل فعال کرده باشد، پخش خودکار انجام نمی‌شود.', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => [ 'media_type' => 'video' ],
		] );

		$repeater->add_control( 'video_muted', [
			'label'        => esc_html__( 'بی‌صدا', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => [ 'media_type' => 'video' ],
		] );

		$repeater->add_control( 'video_loop', [
			'label'        => esc_html__( 'تکرار', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => '',
			'condition'    => [ 'media_type' => 'video' ],
		] );

		$repeater->add_control( 'video_fit', [
			'label'     => esc_html__( 'جای‌گیری ویدیو در قاب', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'contain',
			'options'   => [
				'contain' => esc_html__( 'کامل دیده شود (بدون برش)', 'ifg' ),
				'cover'   => esc_html__( 'قاب را پر کند (با برش)', 'ifg' ),
			],
			'condition' => [ 'media_type' => 'video' ],
		] );

		$repeater->add_control( 'title', [
			'label'       => esc_html__( 'عنوان', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => esc_html__( 'Slide title', 'ifg' ),
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
		] );

		$repeater->add_control( 'caption', [
			'label'       => esc_html__( 'کپشن (اختیاری)', 'ifg' ),
			'description' => esc_html__( 'متن زیر تصویر بندانگشتی. برای استفاده از عنوان، خالی بگذارید.', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
		] );

		$repeater->add_control( 'description', [
			'label'   => esc_html__( 'توضیحات', 'ifg' ),
			'type'    => Controls_Manager::TEXTAREA,
			'rows'    => 5,
			'dynamic' => [ 'active' => true ],
		] );

		$repeater->add_control( 'link', [
			'label'       => esc_html__( 'لینک (اختیاری)', 'ifg' ),
			'type'        => Controls_Manager::URL,
			'placeholder' => 'https://your-link.com',
			'dynamic'     => [ 'active' => true ],
		] );

		$repeater->add_control( 'img_width', [
			'label'       => esc_html__( 'عرض دلخواه تصویر بندانگشتی (px)', 'ifg' ),
			'description' => esc_html__( 'خالی = الگوی پلکانی خودکار.', 'ifg' ),
			'type'        => Controls_Manager::NUMBER,
			'min'         => 80,
			'max'         => 900,
		] );

		$repeater->add_control( 'img_stagger', [
			'label'       => esc_html__( 'جابه‌جایی افقی دلخواه (٪ از عرض ویجت)', 'ifg' ),
			'description' => esc_html__( 'خالی = خودکار. منفی = چپ، مثبت = راست.', 'ifg' ),
			'type'        => Controls_Manager::NUMBER,
			'min'         => -45,
			'max'         => 45,
		] );

		$this->add_control( 'slides', [
			'label'       => esc_html__( 'اسلایدها', 'ifg' ),
			'type'        => Controls_Manager::REPEATER,
			'fields'      => $repeater->get_controls(),
			'title_field' => '{{{ title }}}',
			'condition'   => [ 'source' => 'manual' ],
			'default'     => [
				[ 'title' => 'Inherited Light', 'description' => 'Before fences divided the prairie, horses transformed life across many Plains Nations, becoming companions in travel, hunting, and ceremony. The opening image returns to that enduring relationship between movement, land, and identity.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'Red Earth Theory', 'description' => 'Every stitch recalls a tradition older than the runway. Indigenous beadwork and embroidery have long carried family histories, regional identities, and stories passed from one generation to the next.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'Soft Sovereignty', 'description' => 'Contemporary Indigenous fashion refuses to separate heritage from innovation. Here, bold modern forms coexist with an older understanding: sovereignty begins with the freedom to define one\'s own image.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'Gathering Weather', 'description' => 'Garments shaped by movement echo a life lived close to changing skies. Across generations, clothing has adapted to the landscape rather than asking the landscape to adapt in return.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'Worn Horizon', 'description' => 'Wildflowers bloom across lands that remember both abundance and loss. Beauty persists quietly, much like the cultures that continue to flourish despite centuries of attempted erasure.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'The Fifth Fire', 'description' => 'Many Indigenous teachings describe every generation as a keeper of what comes next. Even the simplest object becomes a reminder that renewal begins with care rather than spectacle.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'Ancestor Season', 'description' => 'Community has always embraced many voices rather than one. Individual expression sits comfortably beside collective identity, each strengthening the other instead of competing with it.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
				[ 'title' => 'A Field Listening', 'description' => 'Red recalls ochre, earth, trade cloth, and ceremony without belonging to any single tradition. Colour becomes memory carried across generations instead of preserved behind glass.', 'image' => [ 'url' => Utils::get_placeholder_image_src() ] ],
			],
		] );



		$this->add_control( 'posts_count', [
			'label'     => esc_html__( 'تعداد محصولات', 'ifg' ),
			'type'      => Controls_Manager::NUMBER,
			'default'   => 12,
			'min'       => 2,
			'max'       => 60,
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'terms_pick', [
			'label'       => esc_html__( 'فیلتر بر اساس دسته‌بندی یا برچسب', 'ifg' ),
			'description' => esc_html__( 'یک یا چند مورد. خالی = همه محصولات.', 'ifg' ),
			'type'        => Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => $this->term_options(),
			'condition'   => [ 'source' => 'product' ],
		] );

		$this->add_control( 'exclude_current', [
			'label'        => esc_html__( 'حذف محصول فعلی', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => [ 'source' => 'product' ],
		] );

		$this->add_control( 'posts_offset', [
			'label'     => esc_html__( 'رد کردن محصولات اول', 'ifg' ),
			'type'      => Controls_Manager::NUMBER,
			'default'   => 0,
			'min'       => 0,
			'max'       => 100,
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'hide_outofstock', [
			'label'        => esc_html__( 'پنهان کردن محصولات ناموجود', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => '',
			'condition'    => [ 'source' => 'product' ],
		] );

		$this->add_control( 'productPrice', [
			'label'     => esc_html__( 'نمایش قیمت', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'content',
			'options'   => [
				'none'    => esc_html__( 'پنهان', 'ifg' ),
				'content' => esc_html__( 'در نمای محتوا', 'ifg' ),
				'both'    => esc_html__( 'در نمای محتوا و زیر تصویر', 'ifg' ),
			],
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'orderby', [
			'label'     => esc_html__( 'مرتب‌سازی بر اساس', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'date',
			'options'   => [
				'date'       => esc_html__( 'تاریخ', 'ifg' ),
				'title'      => esc_html__( 'عنوان', 'ifg' ),
				'menu_order' => esc_html__( 'ترتیب منو', 'ifg' ),
				'rand'       => esc_html__( 'تصادفی', 'ifg' ),
				'price'      => esc_html__( 'قیمت (ووکامرس)', 'ifg' ),
				'popularity' => esc_html__( 'پرفروش‌ترین (ووکامرس)', 'ifg' ),
			],
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'order', [
			'label'     => esc_html__( 'ترتیب', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'DESC',
			'options'   => [
				'DESC' => esc_html__( 'نزولی', 'ifg' ),
				'ASC'  => esc_html__( 'صعودی', 'ifg' ),
			],
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_group_control( Group_Control_Image_Size::get_type(), [
			'name'    => 'thumb',
			'default' => 'large',
			'exclude' => [ 'custom' ],
		] );

		/* -- WooCommerce: Add to cart -- */
		$this->add_control( 'atc_heading', [
			'label'     => esc_html__( 'دکمه افزودن به سبد خرید', 'ifg' ),
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'showAtc', [
			'label'        => esc_html__( 'نمایش دکمه «افزودن به سبد» در نمای محتوا', 'ifg' ),
			'description'  => esc_html__( 'با AJAX و بدون بارگذاری مجدد صفحه. برای محصولات متغیر دکمه به صفحه محصول می‌رود.', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => [ 'source' => 'product' ],
		] );

		$this->add_control( 'atcLabel', [
			'label'       => esc_html__( 'متن دکمه', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => 'Add to cart',
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
			'condition'   => [ 'source' => 'product', 'showAtc' => 'yes' ],
		] );

		$this->add_control( 'atcAddedLabel', [
			'label'       => esc_html__( 'متن پس از افزودن موفق', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => 'Added!',
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
			'condition'   => [ 'source' => 'product', 'showAtc' => 'yes' ],
		] );

		$this->add_control( 'atcViewCart', [
			'label'        => esc_html__( 'نمایش لینک «مشاهده سبد» پس از افزودن', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => 'yes',
			'condition'    => [ 'source' => 'product', 'showAtc' => 'yes' ],
		] );

		$this->add_control( 'atcViewCartLabel', [
			'label'       => esc_html__( 'متن لینک سبد', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => 'View cart',
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
			'condition'   => [ 'source' => 'product', 'showAtc' => 'yes', 'atcViewCart' => 'yes' ],
		] );

		/* -- WooCommerce: Category filter bar -- */
		$this->add_control( 'filter_heading', [
			'label'     => esc_html__( 'نوار فیلتر دسته‌بندی', 'ifg' ),
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'showFilter', [
			'label'        => esc_html__( 'نمایش نوار فیلتر دسته‌بندی', 'ifg' ),
			'description'  => esc_html__( 'فیلتر بدون بارگذاری مجدد صفحه. بدون جاوااسکریپت، دکمه‌ها به صفحه همان دسته‌بندی می‌روند.', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'return_value' => 'yes',
			'default'      => '',
			'condition'    => [ 'source' => 'product' ],
		] );

		$this->add_control( 'filterCats', [
			'label'       => esc_html__( 'دسته‌بندی‌های نوار فیلتر', 'ifg' ),
			'description' => esc_html__( 'خالی = همه دسته‌بندی‌های اصلی که محصول دارند.', 'ifg' ),
			'type'        => Controls_Manager::SELECT2,
			'multiple'    => true,
			'label_block' => true,
			'options'     => $this->category_options(),
			'condition'   => [ 'source' => 'product', 'showFilter' => 'yes' ],
		] );

		$this->add_control( 'filterAllLabel', [
			'label'       => esc_html__( 'برچسب دکمه «همه»', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => 'All',
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
			'condition'   => [ 'source' => 'product', 'showFilter' => 'yes' ],
		] );

		$this->end_controls_section();

		/* ---- Layout ---- */
		$this->start_controls_section( 'section_layout', [
			'label' => esc_html__( 'چیدمان', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_responsive_control( 'height', [
			'label' => esc_html__( 'ارتفاع ویجت', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'vh', 'px' ],
			'range' => [ 'vh' => [ 'min' => 30, 'max' => 100, 'step' => 1 ], 'px' => [ 'min' => 200, 'max' => 1600, 'step' => 1 ] ],
			'default' => [ 'unit' => 'vh', 'size' => 100 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-height: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_control( 'displayMode', [
			'label'       => esc_html__( 'نمایش گالری', 'ifg' ),
			'description' => esc_html__( 'حالت «کل صفحه» برای صفحه اختصاصی گالری است. در ویرایشگر اعمال نمی‌شود.', 'ifg' ),
			'type'        => Controls_Manager::SELECT,
			'default'     => 'page',
			'options'     => [
				'contained' => esc_html__( 'داخل ویجت', 'ifg' ),
				'overlay'   => esc_html__( 'نمای محتوا تمام‌صفحه', 'ifg' ),
				'page'      => esc_html__( 'کل صفحه (قفل اسکرول صفحه)', 'ifg' ),
				'bleed'     => esc_html__( 'عرض کامل مرورگر', 'ifg' ),
			],
		] );

		$this->add_control( 'overlayZ', [
			'label'       => esc_html__( 'z-index پوشش', 'ifg' ),
			'description' => esc_html__( 'اگر هدر چسبان روی گالری می‌آید، این عدد را بزرگ کنید.', 'ifg' ),
			'type'        => Controls_Manager::NUMBER,
			'min'         => 1,
			'max'         => 999999,
			'step'        => 1,
			'default'     => 1000,
			'selectors'   => [ '{{WRAPPER}} .ifg' => '--ifg-z: {{VALUE}};' ],
			'condition'   => [ 'displayMode' => [ 'overlay', 'page' ] ],
		] );

		$this->add_control( 'layoutDir', [
			'label'       => esc_html__( 'جهت چیدمان', 'ifg' ),
			'description' => esc_html__( 'تصویر و متن کدام سمت باشند. «چپ به راست» چیدمان دموی اصلی است.', 'ifg' ),
			'type'        => Controls_Manager::SELECT,
			'default'     => '',
			'options'     => [
				''    => esc_html__( 'پیروی از جهت سایت', 'ifg' ),
				'ltr' => esc_html__( 'چپ به راست (دموی اصلی)', 'ifg' ),
				'rtl' => esc_html__( 'راست به چپ', 'ifg' ),
			],
		] );


		$this->add_control( 'pageZ', [
			'label'       => esc_html__( 'z-index تمام‌صفحه', 'ifg' ),
			'description' => esc_html__( 'اگر هدر سایت روی گالری می‌ماند، این عدد را بزرگ کنید.', 'ifg' ),
			'type'        => Controls_Manager::NUMBER,
			'min'         => 1,
			'max'         => 999999,
			'default'     => 9990,
			'selectors'   => [ '{{WRAPPER}} .ifg' => '--ifg-page-z: {{VALUE}};' ],
			'condition'   => [ 'displayMode' => 'page' ],
		] );


		$this->add_control( 'ratio', [
			'label' => esc_html__( 'نسبت تصویر', 'ifg' ),
			'type' => Controls_Manager::SELECT,
			'default' => '0.8',
			'options' => [ '0.8' => esc_html__( '۴:۵ عمودی (اصلی)', 'ifg' ), '0.6667' => esc_html__( '۲:۳ عمودی', 'ifg' ), '0.75' => esc_html__( '۳:۴ عمودی', 'ifg' ), '1' => esc_html__( '۱:۱ مربع', 'ifg' ), '1.3333' => esc_html__( '۴:۳ افقی', 'ifg' ), '1.7778' => esc_html__( '۱۶:۹ افقی', 'ifg' ) ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-ratio: {{VALUE}};' ],
			
			
		] );

		$this->add_responsive_control( 'gap', [
			'label' => esc_html__( 'فاصله بین اسلایدها', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'vh', 'px' ],
			'range' => [ 'vh' => [ 'min' => 0, 'max' => 40, 'step' => 1 ], 'px' => [ 'min' => 0, 'max' => 400, 'step' => 1 ] ],
			'default' => [ 'unit' => 'vh', 'size' => 12 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-gap: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_responsive_control( 'padTop', [
			'label' => esc_html__( 'فاصله داخلی بالا', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'vh', 'px' ],
			'range' => [ 'vh' => [ 'min' => 0, 'max' => 40, 'step' => 1 ], 'px' => [ 'min' => 0, 'max' => 400, 'step' => 1 ] ],
			'default' => [ 'unit' => 'vh', 'size' => 14 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-pad-top: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_responsive_control( 'widthScale', [
			'label' => esc_html__( 'ضریب اندازه تصویر', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.4,
			'max' => 2,
			'step' => 0.05,
			'default' => 1,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-width-scale: {{VALUE}};' ],
			
		] );

		$this->add_responsive_control( 'staggerScale', [
			'label' => esc_html__( 'پلکان افقی', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0,
			'max' => 2,
			'step' => 0.05,
			'default' => 1,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-stagger-scale: {{VALUE}};' ],
			
		] );

		$this->end_controls_section();
		
		/* ---- Labels & Frame ---- */
		$this->start_controls_section( 'section_labels', [
			'label' => esc_html__( 'برچسب‌ها و قاب', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'backLabel', [
			'label' => esc_html__( 'برچسب دکمه بازگشت', 'ifg' ),
			'type' => Controls_Manager::TEXT,
			'default' => '← back [ESC]',
			'label_block' => true,
			'dynamic' => [ 'active' => true ],
			
			
		] );

		$this->add_control( 'showCounter', [
			'label'        => esc_html__( 'نمایش شمارنده (۰۱ / ۰۸)', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'بله', 'ifg' ),
			'label_off'    => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default'      => '',
		] );

		$this->add_control( 'titleTag', [
			'label'   => esc_html__( 'تگ HTML عنوان محتوا', 'ifg' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'h2',
			'options' => $this->tag_options(),
		] );

		$this->add_control( 'ctaLabel', [
			'label' => esc_html__( 'برچسب لینک اسلاید', 'ifg' ),
			'type' => Controls_Manager::TEXT,
			'default' => 'Read more',
			'label_block' => true,
			'dynamic' => [ 'active' => true ],
			
			'description' => esc_html__( 'وقتی اسلاید لینک داشته باشد در نمای محتوا نمایش داده می‌شود.', 'ifg' ),
		] );

		$this->add_control( 'showFrame', [
			'label' => esc_html__( 'نمایش قاب', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );

		$this->add_control( 'frameTitle', [
			'label' => esc_html__( 'عنوان قاب', 'ifg' ),
			'type' => Controls_Manager::TEXT,
			'default' => 'Inherited Light',
			'label_block' => true,
			'dynamic' => [ 'active' => true ],
			'condition' => [ 'showFrame' => 'yes' ],
			
		] );

		$this->add_control( 'frameTags', [
			'label' => esc_html__( 'تگ‌های قاب (جداشده با کاما)', 'ifg' ),
			'type' => Controls_Manager::TEXT,
			'default' => 'Gallery, Fashion, Culture',
			'label_block' => true,
			'dynamic' => [ 'active' => true ],
			'condition' => [ 'showFrame' => 'yes' ],
			
		] );

		$this->add_control( 'frameTitleTag', [
			'label'     => esc_html__( 'تگ HTML عنوان قاب', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'h3',
			'options'   => $this->tag_options(),
			'condition' => [ 'showFrame' => 'yes' ],
		] );

		$this->add_control( 'frameLinkText', [
			'label' => esc_html__( 'متن لینک قاب', 'ifg' ),
			'type' => Controls_Manager::TEXT,
			'default' => 'Scroll to explore',
			'label_block' => true,
			'dynamic' => [ 'active' => true ],
			'condition' => [ 'showFrame' => 'yes' ],
			
		] );

		$this->add_control( 'frameLinkUrl', [
			'label' => esc_html__( 'آدرس لینک قاب', 'ifg' ),
			'type' => Controls_Manager::URL,
			'default' => [ 'url' => '' ],
			'placeholder' => 'https://your-link.com',
			'dynamic' => [ 'active' => true ],
			'condition' => [ 'showFrame' => 'yes' ],
			
		] );

		$this->end_controls_section();

		/* ---- Scroll & Input ---- */
		$this->start_controls_section( 'section_scroll', [
			'label' => esc_html__( 'اسکرول و ورودی', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'scrollMode', [
			'label'       => esc_html__( 'گرفتن اسکرول', 'ifg' ),
			'description' => esc_html__( 'همیشه: گالری اسکرول صفحه را می‌گیرد. بعد از کلیک: تا کلیک، صفحه عادی اسکرول می‌شود. Esc آن را آزاد می‌کند.', 'ifg' ),
			'type'        => Controls_Manager::SELECT,
			'default'     => 'always',
			'options'     => [
				'always' => esc_html__( 'همیشه (دموی اصلی)', 'ifg' ),
				'click'  => esc_html__( 'بعد از کلیک (تا آن زمان صفحه عادی اسکرول می‌شود)', 'ifg' ),
			],
		] );

		$this->add_control( 'hintText', [
			'label'       => esc_html__( 'متن راهنما', 'ifg' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => 'Click to explore',
			'label_block' => true,
			'dynamic'     => [ 'active' => true ],
			'condition'   => [ 'scrollMode' => 'click' ],
		] );

		$this->add_control( 'wheel', [
			'label' => esc_html__( 'چرخ ماوس', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );

		$this->add_control( 'touch', [
			'label' => esc_html__( 'لمس / کشیدن', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			'description' => esc_html__( 'برای اسکرول عادی در موبایل خاموش کنید.', 'ifg' ),
		] );

		$this->add_control( 'keyboard', [
			'label' => esc_html__( 'صفحه‌کلید (↑ ↓ PgUp PgDn)', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );

		$this->add_control( 'invert', [
			'label' => esc_html__( 'معکوس کردن جهت', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => '',
			
			
		] );




		$this->end_controls_section();

		/* ---- Parallax ---- */
		$this->start_controls_section( 'section_parallax', [
			'label' => esc_html__( 'پارالاکس', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'parallax', [
			'label' => esc_html__( 'فعال‌سازی پارالاکس', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );


		$this->end_controls_section();

		/* ---- Autoplay ---- */
		$this->start_controls_section( 'section_autoplay', [
			'label' => esc_html__( 'پخش خودکار', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'autoplay', [
			'label' => esc_html__( 'اسکرول خودکار', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => '',
			
			
		] );

		$this->add_control( 'autoplaySpeed', [
			'label' => esc_html__( 'سرعت (پیکسل / ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => -400,
			'max' => 400,
			'step' => 5,
			'default' => 40,
			'condition' => [ 'autoplay' => 'yes' ],
			
		] );

		$this->add_control( 'autoplayPause', [
			'label' => esc_html__( 'توقف هنگام هاور', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			'condition' => [ 'autoplay' => 'yes' ],
			
		] );

		$this->end_controls_section();
		
		/* ---- Reveal Animation ---- */
		$this->start_controls_section( 'section_reveal', [
			'label' => esc_html__( 'انیمیشن نمایش', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'loader', [
			'label' => esc_html__( 'نمایش خط بارگذاری', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );

		$this->add_control( 'revealEffect', [
			'label' => esc_html__( 'افکت نمایش تصویر', 'ifg' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'fade',
			'options' => [ 'fade' => esc_html__( 'محو شدن (اصلی)', 'ifg' ), 'rise' => esc_html__( 'محو شدن + بالا آمدن', 'ifg' ), 'clip' => esc_html__( 'نمایش با برش', 'ifg' ), 'scale' => esc_html__( 'بزرگ شدن', 'ifg' ) ],
			
			
			
		] );

		$this->add_control( 'revealDuration', [
			'label' => esc_html__( 'مدت نمایش تصویر (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.1,
			'max' => 4,
			'step' => 0.1,
			'default' => 1,
			
			
		] );


		$this->add_control( 'captionReveal', [
			'label' => esc_html__( 'کپشن حرف‌به‌حرف', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );


		$this->end_controls_section();

		/* ---- Open / Close Transition ---- */
		$this->start_controls_section( 'section_transition', [
			'label' => esc_html__( 'انیمیشن باز و بسته شدن', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'clickAction', [
			'label'       => esc_html__( 'هنگام کلیک روی اسلاید', 'ifg' ),
			'type'        => Controls_Manager::SELECT,
			'default'     => 'content',
			'options'     => [
				'content' => esc_html__( 'باز کردن نمای محتوا', 'ifg' ),
				'link'    => esc_html__( 'رفتن به لینک اسلاید (در صورت نبود لینک، نمای محتوا)', 'ifg' ),
				'none'    => esc_html__( 'هیچ کاری', 'ifg' ),
			],
		] );

		$this->add_control( 'respectReducedMotion', [
			'label'        => esc_html__( 'رعایت تنظیم «کاهش حرکت»', 'ifg' ),
			'description' => esc_html__( 'برای کاربرانی که کاهش حرکت دارند، حرکت را کم می‌کند.', 'ifg' ),
			'type'         => Controls_Manager::SWITCHER,
			'label_on'     => esc_html__( 'بله', 'ifg' ),
			'label_off'    => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default'      => 'yes',
		] );

		$this->add_control( 'openDuration', [
			'label' => esc_html__( 'مدت باز شدن (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.2,
			'max' => 4,
			'step' => 0.1,
			'default' => 1.2,
			
			
		] );

		$this->add_control( 'openEase', [
			'label'   => esc_html__( 'منحنی باز شدن', 'ifg' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'power4.inOut',
			'options' => [ 'power2.inOut' => esc_html__( 'ملایم', 'ifg' ), 'power3.inOut' => esc_html__( 'متعادل', 'ifg' ), 'power4.inOut' => esc_html__( 'قوی', 'ifg' ), 'expo.inOut' => esc_html__( 'خیلی قوی', 'ifg' ), 'none' => esc_html__( 'خطی', 'ifg' ) ],
		] );

		$this->add_control( 'closeDuration', [
			'label' => esc_html__( 'مدت بسته شدن (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.2,
			'max' => 4,
			'step' => 0.1,
			'default' => 1,
			
			
		] );

		$this->add_control( 'closeEase', [
			'label'   => esc_html__( 'منحنی بسته شدن', 'ifg' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'power3.inOut',
			'options' => [ 'power2.inOut' => esc_html__( 'ملایم', 'ifg' ), 'power3.inOut' => esc_html__( 'متعادل', 'ifg' ), 'power4.inOut' => esc_html__( 'قوی', 'ifg' ), 'expo.inOut' => esc_html__( 'خیلی قوی', 'ifg' ), 'none' => esc_html__( 'خطی', 'ifg' ) ],
		] );

		$this->add_control( 'dimOthers', [
			'label' => esc_html__( 'محو کردن اسلایدهای دیگر', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );

		$this->add_control( 'textEffect', [
			'label' => esc_html__( 'انیمیشن متن', 'ifg' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'chars',
			'options' => [ 'chars' => esc_html__( 'حروف (اصلی)', 'ifg' ), 'words' => esc_html__( 'کلمات', 'ifg' ), 'lines' => esc_html__( 'خطوط (حرکت به بالا)', 'ifg' ) ],
			
			
			'description' => esc_html__( 'متن فارسی و عربی خودکار روی «کلمات» تنظیم می‌شود.', 'ifg' ),
		] );



		$this->add_control( 'closeOnEsc', [
			'label' => esc_html__( 'بستن با Esc', 'ifg' ),
			'type' => Controls_Manager::SWITCHER,
			'label_on' => esc_html__( 'بله', 'ifg' ),
			'label_off' => esc_html__( 'خیر', 'ifg' ),
			'return_value' => 'yes',
			'default' => 'yes',
			
			
		] );

		$this->end_controls_section();

		/* ---- Advanced ---- */
		$this->start_controls_section( 'section_advanced', [
			'label' => esc_html__( 'پیشرفته', 'ifg' ),
			'tab'   => Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'sensitivity', [
			'label' => esc_html__( 'حساسیت اسکرول', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.1,
			'max' => 4,
			'step' => 0.1,
			'default' => 1,
			
			
		] );
		$this->add_control( 'smoothness', [
			'label' => esc_html__( 'نرمی حرکت (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.1,
			'max' => 3,
			'step' => 0.05,
			'default' => 0.75,
			
			
		] );
		$this->add_control( 'parallaxStrength', [
			'label'     => esc_html__( 'شدت پارالاکس', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'normal',
			'options'   => [
				'subtle' => esc_html__( 'ملایم', 'ifg' ),
				'normal' => esc_html__( 'معمول', 'ifg' ),
				'strong' => esc_html__( 'قوی', 'ifg' ),
			],
			'condition' => [ 'parallax' => 'yes' ],
		] );
		$this->add_control( 'revealStagger', [
			'label' => esc_html__( 'تأخیر بین اسلایدها (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0,
			'max' => 1,
			'step' => 0.02,
			'default' => 0.12,
			
			
		] );
		$this->add_control( 'captionStagger', [
			'label' => esc_html__( 'فاصله زمانی کپشن (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.005,
			'max' => 0.2,
			'step' => 0.005,
			'default' => 0.025,
			'condition' => [ 'captionReveal' => 'yes' ],
			
		] );
		$this->add_control( 'textStagger', [
			'label' => esc_html__( 'فاصله زمانی متن (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0.002,
			'max' => 0.2,
			'step' => 0.002,
			'default' => 0.01,
			
			
		] );
		$this->add_control( 'textDelay', [
			'label' => esc_html__( 'تأخیر شروع متن (ثانیه)', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 0,
			'max' => 3,
			'step' => 0.05,
			'default' => 0.8,
			
			
		] );
		$this->end_controls_section();

		/* ---- Colors ---- */
		$this->start_controls_section( 'section_colors', [
			'label' => esc_html__( 'رنگ‌ها', 'ifg' ),
			'tab'   => Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'bg', [
			'label' => esc_html__( 'پس‌زمینه', 'ifg' ),
			'type' => Controls_Manager::COLOR,
			'default' => '#eaeeed',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-bg: {{VALUE}};' ],
			
		] );

		$this->add_control( 'captionColor', [
			'label' => esc_html__( 'رنگ کپشن', 'ifg' ),
			'type' => Controls_Manager::COLOR,
			'default' => '#9b9b9b',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-caption-color: {{VALUE}};' ],
			
		] );

		$this->add_control( 'frameColor', [
			'label' => esc_html__( 'رنگ متن قاب', 'ifg' ),
			'type' => Controls_Manager::COLOR,
			'default' => '#9b9b9b',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-frame-color: {{VALUE}};' ],
			
		] );

		$this->add_control( 'titleColor', [
			'label' => esc_html__( 'رنگ عنوان', 'ifg' ),
			'type' => Controls_Manager::COLOR,
			'default' => '#141414',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-title-color: {{VALUE}};' ],
			
		] );

		$this->add_control( 'descColor', [
			'label' => esc_html__( 'رنگ توضیحات', 'ifg' ),
			'type' => Controls_Manager::COLOR,
			'default' => '#9b9b9b',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-desc-color: {{VALUE}};' ],
			
		] );

		$this->add_control( 'backColor', [
			'label' => esc_html__( 'رنگ دکمه بازگشت / لینک', 'ifg' ),
			'type' => Controls_Manager::COLOR,
			'default' => '#111111',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-back-color: {{VALUE}};' ],
			
		] );

		$this->add_control( 'overlayBg', [
			'label'       => esc_html__( 'پس‌زمینه نمای محتوا', 'ifg' ),
			'description' => esc_html__( 'برای استفاده از پس‌زمینه اصلی، خالی بگذارید.', 'ifg' ),
			'type'        => Controls_Manager::COLOR,
			'selectors'   => [ '{{WRAPPER}} .ifg' => '--ifg-overlay-bg: {{VALUE}};' ],
		] );

		$this->end_controls_section();
		
		/* ---- Sizes & Images ---- */
		$this->start_controls_section( 'section_sizes', [
			'label' => esc_html__( 'اندازه‌ها و تصاویر', 'ifg' ),
			'tab'   => Controls_Manager::TAB_STYLE,
		] );

		$this->add_responsive_control( 'captionSize', [
			'label' => esc_html__( 'اندازه کپشن', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range' => [ 'px' => [ 'min' => 8, 'max' => 28, 'step' => 1 ] ],
			'default' => [ 'unit' => 'px', 'size' => 12 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-caption-size: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_responsive_control( 'titleSize', [
			'label' => esc_html__( 'حداکثر اندازه عنوان', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range' => [ 'px' => [ 'min' => 28, 'max' => 180, 'step' => 1 ] ],
			'default' => [ 'unit' => 'px', 'size' => 72 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-title-size: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_responsive_control( 'descSize', [
			'label' => esc_html__( 'حداکثر اندازه توضیحات', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range' => [ 'px' => [ 'min' => 12, 'max' => 40, 'step' => 1 ] ],
			'default' => [ 'unit' => 'px', 'size' => 18 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-desc-size: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_control( 'radius', [
			'label' => esc_html__( 'گردی گوشه تصویر', 'ifg' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'px', '%' ],
			'range' => [ 'px' => [ 'min' => 0, 'max' => 60, 'step' => 1 ], '%' => [ 'min' => 0, 'max' => 50, 'step' => 1 ] ],
			'default' => [ 'unit' => 'px', 'size' => 0 ],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-radius: {{SIZE}}{{UNIT}};' ],
			
		] );

		$this->add_control( 'imgFit', [
			'label'       => esc_html__( 'نحوه جاگیری تصویر', 'ifg' ),
			'description' => esc_html__( 'برش: کادر را پر می‌کند. کامل: کل تصویر دیده می‌شود (مناسب محصولات).', 'ifg' ),
			'type'        => Controls_Manager::SELECT,
			'default'     => 'cover',
			'options'     => [
				'cover'   => esc_html__( 'برش برای پر کردن (cover)', 'ifg' ),
				'contain' => esc_html__( 'نمایش کامل تصویر (contain)', 'ifg' ),
				'fill'    => esc_html__( 'کشیده (fill)', 'ifg' ),
			],
			'render_type' => 'template',
			'selectors'   => [ '{{WRAPPER}} .ifg' => '--ifg-img-fit: {{VALUE}};' ],
		] );

		$this->add_control( 'imgPosition', [
			'label'     => esc_html__( 'موقعیت تصویر', 'ifg' ),
			'type'      => Controls_Manager::SELECT,
			'default'   => 'center center',
			'options'   => [
				'center center' => esc_html__( 'وسط', 'ifg' ),
				'center top'    => esc_html__( 'بالا', 'ifg' ),
				'center bottom' => esc_html__( 'پایین', 'ifg' ),
				'left center'   => esc_html__( 'چپ', 'ifg' ),
				'right center'  => esc_html__( 'راست', 'ifg' ),
			],
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-img-pos: {{VALUE}};' ],
		] );

		$this->add_control( 'imgBg', [
			'label'       => esc_html__( 'پس‌زمینه کادر تصویر', 'ifg' ),
			'description' => esc_html__( 'رنگ پشت تصویر وقتی کادر را کامل پر نمی‌کند (مثلاً سفید برای عکس محصولات).', 'ifg' ),
			'type'        => Controls_Manager::COLOR,
			'selectors'   => [ '{{WRAPPER}} .ifg' => '--ifg-img-bg: {{VALUE}};' ],
		] );

		$this->add_control( 'imgScale', [
			'label' => esc_html__( 'زوم داخلی تصویر', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 1,
			'max' => 1.6,
			'step' => 0.02,
			'default' => 1.2,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-img-scale: {{VALUE}};' ],
			
		] );

		$this->add_control( 'hoverScale', [
			'label' => esc_html__( 'زوم هاور', 'ifg' ),
			'type' => Controls_Manager::NUMBER,
			'min' => 1,
			'max' => 1.8,
			'step' => 0.02,
			'default' => 1.2,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-hover-scale: {{VALUE}};' ],
			
		] );

		$this->end_controls_section();

		/* ---- Image effects ---- */
		$this->start_controls_section( 'section_image_fx', [
			'label' => esc_html__( 'افکت‌های تصویر', 'ifg' ),
			'tab'   => Controls_Manager::TAB_STYLE,
		] );

		$this->add_group_control( Group_Control_Css_Filter::get_type(), [
			'name'     => 'img_filters',
			'selector' => '{{WRAPPER}} .ifg__img img',
		] );

		$this->add_control( 'img_filters_hover_heading', [
			'label'     => esc_html__( 'هنگام هاور', 'ifg' ),
			'type'      => Controls_Manager::HEADING,
			'separator' => 'before',
		] );

		$this->add_group_control( Group_Control_Css_Filter::get_type(), [
			'name'     => 'img_filters_hover',
			'selector' => '{{WRAPPER}} .ifg__slide:hover .ifg__img img',
		] );

		$this->end_controls_section();

		/* ---- Shop: add-to-cart button & filter bar (style tab) ---- */
		$this->start_controls_section( 'section_shop_style', [
			'label'     => esc_html__( 'دکمه سبد خرید و فیلتر', 'ifg' ),
			'tab'       => Controls_Manager::TAB_STYLE,
			'condition' => [ 'source' => 'product' ],
		] );

		$this->add_control( 'atcBg', [
			'label'     => esc_html__( 'پس‌زمینه دکمه سبد', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-atc-bg: {{VALUE}};' ],
		] );

		$this->add_control( 'atcColor', [
			'label'     => esc_html__( 'رنگ متن دکمه سبد', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-atc-color: {{VALUE}};' ],
		] );

		$this->add_control( 'atcBgHover', [
			'label'     => esc_html__( 'پس‌زمینه دکمه سبد (هاور)', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-atc-bg-hover: {{VALUE}};' ],
		] );

		$this->add_control( 'atcColorHover', [
			'label'     => esc_html__( 'رنگ متن دکمه سبد (هاور)', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-atc-color-hover: {{VALUE}};' ],
		] );

		$this->add_control( 'atcRadius', [
			'label'      => esc_html__( 'گردی گوشه‌های دکمه سبد', 'ifg' ),
			'type'       => Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [ 'px' => [ 'min' => 0, 'max' => 60, 'step' => 1 ] ],
			'selectors'  => [ '{{WRAPPER}} .ifg' => '--ifg-atc-radius: {{SIZE}}{{UNIT}};' ],
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'atc_typography',
			'label'    => esc_html__( 'دکمه سبد خرید', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__atc',
		] );

		$this->add_control( 'filterColor', [
			'label'     => esc_html__( 'رنگ متن فیلتر', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'separator' => 'before',
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-filter-color: {{VALUE}};' ],
		] );

		$this->add_control( 'filterBg', [
			'label'     => esc_html__( 'پس‌زمینه دکمه‌های فیلتر', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-filter-bg: {{VALUE}};' ],
		] );

		$this->add_control( 'filterActiveColor', [
			'label'     => esc_html__( 'رنگ متن فیلتر فعال', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-filter-active-color: {{VALUE}};' ],
		] );

		$this->add_control( 'filterActiveBg', [
			'label'     => esc_html__( 'پس‌زمینه فیلتر فعال', 'ifg' ),
			'type'      => Controls_Manager::COLOR,
			'selectors' => [ '{{WRAPPER}} .ifg' => '--ifg-filter-active-bg: {{VALUE}};' ],
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'filter_typography',
			'label'    => esc_html__( 'دکمه‌های فیلتر', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__filter',
		] );

		$this->end_controls_section();

		/* ---- Typography (style tab) ---- */
		$this->start_controls_section( 'section_typography', [
			'label' => esc_html__( 'تایپوگرافی', 'ifg' ),
			'tab'   => Controls_Manager::TAB_STYLE,
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'caption_typography',
			'label'    => esc_html__( 'کپشن', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__caption',
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'frame_typography',
			'label'    => esc_html__( 'قاب', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__frame',
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'title_typography',
			'label'    => esc_html__( 'عنوان محتوا', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__title',
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'desc_typography',
			'label'    => esc_html__( 'توضیحات محتوا', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__desc',
		] );

		$this->add_group_control( Group_Control_Typography::get_type(), [
			'name'     => 'back_typography',
			'label'    => esc_html__( 'دکمه بازگشت و لینک', 'ifg' ),
			'selector' => '{{WRAPPER}} .ifg__back, {{WRAPPER}} .ifg__cta',
		] );

		$this->end_controls_section();
	}

	/**
	 * Build the rel="" value for a link control value.
	 */
	private function link_rel( $link ) {
		$rel = [];
		if ( ! empty( $link['is_external'] ) ) {
			$rel[] = 'noopener';
		}
		if ( ! empty( $link['nofollow'] ) ) {
			$rel[] = 'nofollow';
		}
		return implode( ' ', $rel );
	}

	/**
	 * Responsive candidates for an attachment, so small thumbnails do not download the full image.
	 */
	private function img_srcset( $id, $s ) {
		if ( ! $id ) {
			return '';
		}
		$size = ! empty( $s['thumb_size'] ) && 'custom' !== $s['thumb_size'] ? $s['thumb_size'] : 'large';
		$set  = wp_get_attachment_image_srcset( $id, $size );
		return $set ? $set : '';
	}

	/**
	 * Plain-text excerpt without running the_content filters (page builders make those slow).
	 */
	private function plain_excerpt( $post ) {
		$text = '' !== trim( (string) $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), 45 );
	}

	/* ------------------------------------------------------------------ */
	/* Video helpers                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Recognise a YouTube / Vimeo URL. Returns [ provider, id, hash ] or null.
	 */
	private function parse_video_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}
		if ( preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m ) ) {
			return [ 'youtube', $m[1], '' ];
		}
		if ( preg_match( '~vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/|album/\d+/video/)?(\d{5,})(?:/([A-Za-z0-9]{6,}))?~i', $url, $m ) ) {
			$hash = $m[2] ?? '';
			if ( '' === $hash && preg_match( '~[?&]h=([A-Za-z0-9]+)~', $url, $h ) ) {
				$hash = $h[1];
			}
			return [ 'vimeo', $m[1], $hash ];
		}
		return null;
	}

	/**
	 * Poster for a YouTube / Vimeo video when the editor did not pick an image. Cached, and never blocks for long.
	 */
	private function remote_poster( $provider, $id, $hash = '' ) {
		$key = 'ifg_poster_' . $provider . '_' . md5( $id . $hash );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return (string) $hit;
		}

		$url = '';
		if ( 'youtube' === $provider ) {
			$max = 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/maxresdefault.jpg';
			$res = wp_remote_head( $max, [ 'timeout' => 3 ] );
			$url = ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) )
				? $max
				: 'https://i.ytimg.com/vi/' . rawurlencode( $id ) . '/hqdefault.jpg';
		} elseif ( 'vimeo' === $provider ) {
			$page = 'https://vimeo.com/' . $id . ( $hash ? '/' . $hash : '' );
			$res  = wp_remote_get( add_query_arg( [ 'url' => $page, 'width' => 1280 ], 'https://vimeo.com/api/oembed.json' ), [ 'timeout' => 3 ] );
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$data = json_decode( wp_remote_retrieve_body( $res ), true );
				if ( is_array( $data ) && ! empty( $data['thumbnail_url'] ) ) {
					$url = esc_url_raw( $data['thumbnail_url'] );
				}
			}
		}

		set_transient( $key, $url, $url ? 30 * DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $url;
	}

	/**
	 * Normalised video description for one repeater row, or null when the row is an image slide
	 * (or its video URL is not usable, in which case it simply stays an image slide).
	 */
	private function build_video( $item ) {
		if ( 'video' !== ( $item['media_type'] ?? 'image' ) ) {
			return null;
		}

		$file     = ! empty( $item['video_file']['url'] ) ? (string) $item['video_file']['url'] : '';
		$url      = trim( (string) ( $item['video_url'] ?? '' ) );
		$autoplay = 'yes' === ( $item['video_autoplay'] ?? 'yes' );
		$muted    = 'yes' === ( $item['video_muted'] ?? 'yes' );
		$loop     = 'yes' === ( $item['video_loop'] ?? '' );
		$fit      = 'cover' === ( $item['video_fit'] ?? 'contain' ) ? 'cover' : 'contain';

		$type  = '';
		$src   = '';
		$id    = '';
		$hash  = '';
		$watch = '';

		$parsed = $file ? null : $this->parse_video_url( $url );

		if ( $parsed ) {
			list( $type, $id, $hash ) = $parsed;
			if ( 'youtube' === $type ) {
				$args = [ 'autoplay' => $autoplay ? 1 : 0, 'mute' => $muted ? 1 : 0, 'playsinline' => 1, 'rel' => 0, 'modestbranding' => 1 ];
				if ( $loop ) {
					$args['loop']     = 1;
					$args['playlist'] = $id;
				}
				$src   = add_query_arg( $args, 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ) );
				$watch = 'https://www.youtube.com/watch?v=' . rawurlencode( $id );
			} else {
				$args = [ 'autoplay' => $autoplay ? 1 : 0, 'muted' => $muted ? 1 : 0, 'loop' => $loop ? 1 : 0, 'dnt' => 1, 'playsinline' => 1 ];
				if ( $hash ) {
					$args['h'] = $hash;
				}
				$src   = add_query_arg( $args, 'https://player.vimeo.com/video/' . rawurlencode( $id ) );
				$watch = 'https://vimeo.com/' . rawurlencode( $id ) . ( $hash ? '/' . rawurlencode( $hash ) : '' );
			}
		} else {
			$cand = $file ? $file : $url;
			$ext  = strtolower( pathinfo( (string) wp_parse_url( $cand, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( $cand && in_array( $ext, [ 'mp4', 'm4v', 'webm', 'ogv' ], true ) ) {
				$type  = 'mp4';
				$src   = esc_url_raw( $cand );
				$watch = $src;
			}
		}

		if ( '' === $type || '' === $src ) {
			return null;
		}

		return [
			'type'     => $type,
			'src'      => $src,
			'id'       => $id,
			'hash'     => $hash,
			'watch'    => $watch,
			'autoplay' => $autoplay,
			'muted'    => $muted,
			'loop'     => $loop,
			'fit'      => $fit,
		];
	}

	/* ------------------------------------------------------------------ */
	/* Slides                                                              */
	/* ------------------------------------------------------------------ */

	private function is_product_source( $s ) {
		$source = $s['source'] ?? 'manual';
		return ( 'product' === $source || 'posts' === $source ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Collect the slides from the repeater or a WooCommerce product query.
	 */
	private function get_slides( $s ) {
		$out    = [];
		$source = $s['source'] ?? 'manual';
		if ( 'posts' === $source ) {
			$source = 'product'; // legacy value from before the source cleanup
		}

		$blank = [
			'id' => 0, 'caption' => '', 'desc' => '', 'url' => '', 'new_tab' => false, 'rel' => '', 'width' => '', 'stagger' => '',
			'srcset' => '', 'price' => '', 'stock' => '', 'atc' => null, 'video' => null, 'rating' => null, 'sale' => null,
		];

		if ( 'product' === $source && function_exists( 'wc_get_product' ) ) {
			$orderby = $s['orderby'] ?? 'date';

			$args = [
				'post_type'              => 'product',
				'posts_per_page'         => max( 2, (int) ( $s['posts_count'] ?? 12 ) ),
				'offset'                 => max( 0, (int) ( $s['posts_offset'] ?? 0 ) ),
				'order'                  => $s['order'] ?? 'DESC',
				'post_status'            => 'publish',
				'has_password'           => false,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => [ [ 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ] ],
			];

			if ( 'price' === $orderby ) {
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery
			} elseif ( 'popularity' === $orderby ) {
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery
			} else {
				$args['orderby'] = $orderby;
			}

			// admin-ajax.php has no "current page": the filter request sends it along in $this->ctx_current.
			if ( null !== $this->ctx_current ) {
				$current_id = (int) $this->ctx_current;
			} else {
				$current_id = ( is_singular() || \Elementor\Plugin::$instance->editor->is_edit_mode() ) ? (int) get_the_ID() : 0;
			}
			if ( 'yes' === ( $s['exclude_current'] ?? 'yes' ) && $current_id ) {
				$args['post__not_in'] = [ $current_id ];
			}

			$tax_query = [];

			// WooCommerce: respect catalogue visibility ("Search results only" / hidden products) and, optionally, stock.
			if ( taxonomy_exists( 'product_visibility' ) ) {
				$hide = [ 'exclude-from-catalog' ];
				if ( 'yes' === ( $s['hide_outofstock'] ?? '' ) ) {
					$hide[] = 'outofstock';
				}
				$tax_query[] = [ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => $hide, 'operator' => 'NOT IN' ];
			}

			// Term picker: "taxonomy|id" values, OR inside a taxonomy, AND between taxonomies.
			$by_tax = [];
			foreach ( (array) ( $s['terms_pick'] ?? [] ) as $v ) {
				$parts = explode( '|', (string) $v, 2 );
				if ( 2 === count( $parts ) && taxonomy_exists( $parts[0] ) ) {
					$by_tax[ $parts[0] ][] = (int) $parts[1];
				}
			}
			// Category chosen in the frontend filter bar replaces the editor's category pick (tags still apply).
			if ( $this->force_term > 0 && taxonomy_exists( 'product_cat' ) ) {
				$by_tax['product_cat'] = [ (int) $this->force_term ];
			}
			foreach ( $by_tax as $tax => $ids ) {
				$tax_query[] = [ 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $ids ];
			}

			if ( $tax_query ) {
				$tax_query['relation'] = 'AND';
				$args['tax_query']     = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
			}

			$query      = new \WP_Query( $args );
			$price_mode = $this->resolve_price( $s );
			$show_pr    = 'none' !== $price_mode;
			$cap_pr     = 'both' === $price_mode;
			$show_atc   = 'yes' === ( $s['showAtc'] ?? 'yes' );
			$atc_label  = '' !== trim( (string) ( $s['atcLabel'] ?? '' ) ) ? (string) $s['atcLabel'] : __( 'Add to cart', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

			foreach ( $query->posts as $post ) {
				$thumb_id = get_post_thumbnail_id( $post );
				$src      = Group_Control_Image_Size::get_attachment_image_src( $thumb_id, 'thumb', $s );
				if ( ! $src ) {
					continue;
				}

				$price   = '';
				$caption = '';
				$stock   = '';
				$atc     = null;
				$rating  = null;
				$sale    = null;

				$product = wc_get_product( $post->ID );
				if ( $product ) {
					if ( $show_pr ) {
						$price = wp_kses_post( $product->get_price_html() );
					}
					if ( $cap_pr && '' !== (string) $product->get_price() ) {
						$caption = get_the_title( $post ) . ' — ' . html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
					}
					// Rating only when there are reviews. Sale only for a real discount (variable products have no single regular price, so no badge).
					$avg   = (float) $product->get_average_rating();
					$count = (int) $product->get_review_count();
					if ( $count > 0 && $avg > 0 ) {
						$rating = [ 'avg' => min( 5.0, $avg ), 'count' => $count ];
					}
					$reg     = (float) $product->get_regular_price();
					$sale_pr = (float) $product->get_sale_price();
					if ( $product->is_on_sale() && $reg > 0 && $sale_pr >= 0 && $sale_pr < $reg ) {
						$pct = (int) round( ( $reg - $sale_pr ) / $reg * 100 );
						if ( $pct > 0 ) {
							$sale = [ 'pct' => $pct, 'save' => html_entity_decode( wp_strip_all_tags( wc_price( $reg - $sale_pr ) ), ENT_QUOTES, 'UTF-8' ) ];
						}
					}
					if ( $show_pr && ! $product->is_in_stock() ) {
						$stock = __( 'Out of stock', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
					}
					if ( $show_atc && $product->is_purchasable() && $product->is_in_stock() ) {
						// Simple products can be added through WooCommerce's own ?wc-ajax=add_to_cart endpoint.
						// Everything else (variable, grouped, external ...) needs a choice first, so it links to the product.
						$ajax = $product->supports( 'ajax_add_to_cart' );
						$atc  = [
							'id'   => (int) $product->get_id(),
							'ajax' => (bool) $ajax,
							'url'  => $product->add_to_cart_url(),
							'text' => $ajax ? $atc_label : $product->add_to_cart_text(),
						];
					}
				}

				$out[] = array_merge( $blank, [
					'id'      => (int) $post->ID,
					'src'     => $src,
					'alt'     => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ?: get_the_title( $post ),
					'title'   => get_the_title( $post ),
					'caption' => $caption,
					'desc'    => $this->plain_excerpt( $post ),
					'url'     => get_permalink( $post ),
					'srcset'  => $this->img_srcset( $thumb_id, $s ),
					'price'   => $price,
					'stock'   => $stock,
					'atc'     => $atc,
					'rating'  => $rating,
					'sale'    => $sale,
				] );
			}
			wp_reset_postdata();
			return $out;
		}

		$placeholder = Utils::get_placeholder_image_src();

		foreach ( (array) ( $s['slides'] ?? [] ) as $item ) {
			$id  = ! empty( $item['image']['id'] ) ? (int) $item['image']['id'] : 0;
			$src = $id ? Group_Control_Image_Size::get_attachment_image_src( $id, 'thumb', $s ) : '';
			if ( ! $src && ! empty( $item['image']['url'] ) ) {
				$src = $item['image']['url'];
			}
			if ( ! $src ) {
				continue;
			}

			// Video slides keep an image as thumbnail / poster: Flip morphs that image, the player is mounted afterwards.
			$video = $this->build_video( $item );
			if ( $video && 'mp4' !== $video['type'] ) {
				$custom_poster = $id || ( ! empty( $item['image']['url'] ) && $item['image']['url'] !== $placeholder );
				if ( ! $custom_poster ) {
					$remote = $this->remote_poster( $video['type'], $video['id'], $video['hash'] );
					if ( $remote ) {
						$src = $remote;
					}
				}
			}

			$alt  = $id ? get_post_meta( $id, '_wp_attachment_image_alt', true ) : '';
			$link = isset( $item['link'] ) && is_array( $item['link'] ) ? $item['link'] : [];

			$out[] = array_merge( $blank, [
				'src'     => $src,
				'alt'     => $alt ?: ( $item['title'] ?? '' ),
				'title'   => $item['title'] ?? '',
				'caption' => $item['caption'] ?? '',
				'desc'    => $item['description'] ?? '',
				'url'     => $link['url'] ?? '',
				'new_tab' => ! empty( $link['is_external'] ),
				'rel'     => $this->link_rel( $link ),
				'width'   => $item['img_width'] ?? '',
				'stagger' => $item['img_stagger'] ?? '',
				'srcset'  => $this->img_srcset( $id, $s ),
				'video'   => $video,
			] );
		}

		return $out;
	}

	/**
	 * One display option replaces the old overlay / page / full-width switches.
	 * Widgets saved before the merge only have the old keys, so read those once.
	 */
	private function resolve_display_mode( $s ) {
		$raw = $this->get_data( 'settings' );
		$raw = is_array( $raw ) ? $raw : [];
		if ( array_key_exists( 'displayMode', $raw ) ) {
			return in_array( $s['displayMode'] ?? '', [ 'contained', 'overlay', 'page', 'bleed' ], true ) ? $s['displayMode'] : 'contained';
		}
		if ( 'yes' === ( $raw['pageMode'] ?? '' ) ) {
			return 'page';
		}
		if ( 'fixed' === ( $raw['overlay'] ?? '' ) ) {
			return 'overlay';
		}
		if ( 'yes' === ( $raw['fullBleed'] ?? '' ) ) {
			return 'bleed';
		}
		// Not migrated yet: keep the old default. Migrated: a missing key means a new widget, which gets the new default.
		return self::display_mode_migrated() ? 'page' : 'contained';
	}

	/**
	 * Settings as saved on the element (no defaults). Lets legacy widgets keep their old values.
	 */
	private function raw_settings() {
		$raw = $this->get_data( 'settings' );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * "none" | "content" | "both", merged from the old showPrice / priceInCaption switches.
	 */
	private function resolve_price( $s ) {
		$raw = $this->raw_settings();
		if ( array_key_exists( 'productPrice', $raw ) ) {
			return in_array( $s['productPrice'] ?? '', [ 'none', 'content', 'both' ], true ) ? $s['productPrice'] : 'content';
		}
		if ( 'yes' !== ( $raw['showPrice'] ?? 'yes' ) ) {
			return 'none';
		}
		return 'yes' === ( $raw['priceInCaption'] ?? '' ) ? 'both' : 'content';
	}

	/**
	 * Simple strength picker. A custom list saved by an older version still wins.
	 */
	private function parallax_speeds( $s ) {
		$raw = $this->raw_settings();
		if ( ! empty( $raw['parallaxSpeeds'] ) ) {
			return (string) $raw['parallaxSpeeds'];
		}
		$map = [
			'subtle' => '1.1, 0.9, 1.07, 0.93, 1.09, 0.92',
			'strong' => '1.6, 0.6, 1.4, 0.7, 1.5, 0.65',
		];
		return $map[ $s['parallaxStrength'] ?? 'normal' ] ?? '1.3, 0.8, 1.15, 0.7, 1.25, 0.85';
	}

	/* ------------------------------------------------------------------ */
	/* Category filter (frontend)                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Categories shown in the filter bar.
	 */
	private function get_filter_terms( $s ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return [];
		}
		$ids  = array_filter( array_map( 'absint', (array) ( $s['filterCats'] ?? [] ) ) );
		$args = [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ];
		if ( $ids ) {
			$args['include'] = $ids;
			$args['orderby'] = 'include';
		} else {
			$args['parent']  = 0;
			$args['exclude'] = [ (int) get_option( 'default_product_cat' ) ];
		}
		$terms = get_terms( $args );
		return ( is_wp_error( $terms ) || ! is_array( $terms ) ) ? [] : $terms;
	}

	/**
	 * Find an element by id in Elementor's nested element data.
	 */
	private static function find_element( $elements, $id ) {
		foreach ( (array) $elements as $el ) {
			if ( ( $el['id'] ?? '' ) === $id ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) ) {
				$found = self::find_element( $el['elements'], $id );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Entry point for the "ifg_filter" AJAX action: rebuild this widget from the saved Elementor data of the page it
	 * lives on and render its slides for one category. Returns an array for wp_send_json_success() or a WP_Error.
	 */
	public static function ajax_payload( $post_id, $widget_id, $term, $current ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'ifg_no_woo', 'WooCommerce is not active.' );
		}

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'ifg_no_post', 'Unknown page.' );
		}
		// Only published, unprotected pages are readable by everyone; editors may also use drafts (editor preview).
		$can_edit = current_user_can( 'edit_post', $post_id );
		if ( ! $can_edit && ( 'publish' !== $post->post_status || post_password_required( $post ) ) ) {
			return new \WP_Error( 'ifg_forbidden', 'Not available.' );
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );
		if ( ! $document ) {
			return new \WP_Error( 'ifg_no_doc', 'Not an Elementor page.' );
		}

		$data = self::find_element( $document->get_elements_data(), $widget_id );
		if ( ! $data && $can_edit ) {
			$data = self::find_element( $document->get_elements_data( 'draft' ), $widget_id );
		}
		if ( ! $data || 'ifg_gallery' !== ( $data['widgetType'] ?? '' ) ) {
			return new \WP_Error( 'ifg_no_widget', 'Widget not found.' );
		}

		$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $data );
		if ( ! $widget instanceof self ) {
			return new \WP_Error( 'ifg_bad_widget', 'Widget not found.' );
		}

		return $widget->build_payload( (int) $term, (int) $current );
	}

	/**
	 * Slides + content groups for one category, using exactly the markup render() produces.
	 */
	private function build_payload( $term, $current ) {
		$s = $this->get_settings_for_display();
		if ( ! $this->is_product_source( $s ) || 'yes' !== ( $s['showFilter'] ?? '' ) ) {
			return new \WP_Error( 'ifg_filter_off', 'Filtering is not enabled for this widget.' );
		}

		$this->ctx_current = $current;
		$this->force_term  = ( $term > 0 && term_exists( $term, 'product_cat' ) ) ? $term : 0;

		$slides = $this->get_slides( $s );

		return [
			'slides' => $this->render_slides_html( $slides, $s ),
			'groups' => $this->render_groups_html( $slides, $s ),
			'count'  => count( $slides ),
		];
	}

	/* ------------------------------------------------------------------ */
	/* Markup                                                              */
	/* ------------------------------------------------------------------ */

	private function click_action( $s ) {
		return in_array( $s['clickAction'] ?? 'content', [ 'content', 'link', 'none' ], true ) ? $s['clickAction'] : 'content';
	}

	/**
	 * The <figure> thumbnails. Used by render() and by the AJAX filter, so both stay identical.
	 */
	private function render_slides_html( $slides, $s ) {
		$click_action = $this->click_action( $s );
		$eager_count  = 6; // Images in the first screenful load right away; the rest are native lazy (GSAP only needs their boxes, which CSS sizes).
		$video_word   = __( 'video', 'ifg' );

		ob_start();
		foreach ( $slides as $i => $sl ) :
			$style = '';
			if ( '' !== $sl['width'] && null !== $sl['width'] ) {
				$style .= '--img-px:' . (float) $sl['width'] . 'px;';
			}
			if ( '' !== $sl['stagger'] && null !== $sl['stagger'] ) {
				// Unitless: 1 = 1% of the widget width (the stylesheet multiplies by its horizontal unit).
				$style .= '--stagger:' . (float) $sl['stagger'] . ';';
			}
			$caption = '' !== trim( (string) $sl['caption'] ) ? $sl['caption'] : $sl['title'];
			$href    = ! empty( $sl['url'] ) ? $sl['url'] : '';
			$has_a   = $href && 'none' !== $click_action;
			$label   = wp_strip_all_tags( $sl['title'] ?: $caption );
			$video   = $sl['video'];
			$vattr   = '';
			if ( $video ) {
				$label_v = $label . ', ' . $video_word;
				$vattr   = ' data-video-type="' . esc_attr( $video['type'] ) . '"'
					. ' data-video-src="' . esc_url( $video['src'] ) . '"'
					. ' data-video-autoplay="' . ( $video['autoplay'] ? '1' : '0' ) . '"'
					. ' data-video-muted="' . ( $video['muted'] ? '1' : '0' ) . '"'
					. ' data-video-loop="' . ( $video['loop'] ? '1' : '0' ) . '"'
					. ' data-video-fit="' . esc_attr( $video['fit'] ) . '"';
			} else {
				$label_v = $label;
			}
			?>
			<figure class="ifg__slide" data-index="<?php echo (int) $i; ?>"<?php echo $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?><?php echo $href ? ' data-href="' . esc_url( $href ) . '"' : ''; ?><?php echo ( $href && $sl['new_tab'] ) ? ' data-target="_blank"' : ''; ?><?php echo $vattr; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above ?><?php echo $has_a ? '' : ' aria-label="' . esc_attr( $label_v ) . '"'; ?>>
				<div class="ifg__img">
					<?php
					// The gallery measures and copies these images up front, so they must never be lazy-loaded
					// (the extra classes/attributes are the opt-out flags of the common lazy-load plugins).
					?>
					<img src="<?php echo esc_url( $sl['src'] ); ?>"<?php echo $sl['srcset'] ? ' srcset="' . esc_attr( $sl['srcset'] ) . '" sizes="(max-width: 767px) 70vw, 40vw"' : ''; ?> data-full="<?php echo esc_url( $sl['src'] ); ?>" alt="<?php echo esc_attr( $sl['alt'] ); ?>" class="skip-lazy no-lazy" <?php echo $i < $eager_count ? 'loading="eager" fetchpriority="' . ( 0 === $i ? 'high' : 'auto' ) . '"' : 'loading="lazy"'; ?> decoding="async" draggable="false" data-skip-lazy="1" data-no-lazy="1">
					<?php if ( $video ) : ?>
						<span class="ifg__play" aria-hidden="true"></span>
					<?php endif; ?>
				</div>
				<figcaption class="ifg__caption" dir="auto"><?php echo esc_html( $caption ); ?></figcaption>
				<?php if ( $has_a ) : ?>
					<?php // A real link: crawlers, "open in new tab", copy-link and middle-click all work. The script turns a plain click into the content view. ?>
					<a class="ifg__slidelink" href="<?php echo esc_url( $href ); ?>"<?php echo $sl['new_tab'] ? ' target="_blank"' : ''; ?><?php echo $sl['rel'] ? ' rel="' . esc_attr( $sl['rel'] ) . '"' : ''; ?> draggable="false"><span class="ifg-sr"><?php echo esc_html( $label_v ); ?></span></a>
				<?php endif; ?>

				<?php
				// No-JS fallback: the gallery turns into a plain grid and this block carries what the (script-only) content view
				// would have shown. It is display:none as soon as the script has started (class .ifg--js).
				$has_fb = '' !== trim( (string) $sl['desc'] ) || '' !== (string) $sl['price'] || $sl['atc'] || $video;
				if ( $has_fb ) :
					?>
					<div class="ifg__fallback">
						<?php if ( '' !== (string) $sl['price'] ) : ?>
							<div class="ifg__fb-price" dir="auto"><?php echo $sl['price']; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised with wp_kses_post() ?></div>
						<?php endif; ?>
						<?php if ( '' !== trim( (string) $sl['desc'] ) ) : ?>
							<p class="ifg__fb-desc" dir="auto"><?php echo esc_html( $sl['desc'] ); ?></p>
						<?php endif; ?>
						<?php if ( $video && ! empty( $video['watch'] ) ) : ?>
							<a class="ifg__fb-link ifg__fb-video" href="<?php echo esc_url( $video['watch'] ); ?>" rel="noopener"><?php echo esc_html( ucfirst( $video_word ) ); ?> ▸</a>
						<?php endif; ?>
						<?php if ( $sl['atc'] ) : ?>
							<a class="ifg__fb-link ifg__fb-atc" href="<?php echo esc_url( $sl['atc']['url'] ); ?>" rel="nofollow"><?php echo esc_html( $sl['atc']['text'] ); ?><span class="ifg-sr"> — <?php echo esc_html( $label ); ?></span></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</figure>
			<?php
		endforeach;
		return ob_get_clean();
	}

	/**
	 * The per-slide text blocks of the content view. Used by render() and by the AJAX filter.
	 */
	private function render_groups_html( $slides, $s ) {
		$title_tag    = $this->safe_tag( $s['titleTag'] ?? 'h2', 'h2' );
		$show_counter = 'yes' === ( $s['showCounter'] ?? '' );
		$total        = count( $slides );
		$wid          = $this->get_id();
		$cart_url     = ( 'yes' === ( $s['atcViewCart'] ?? 'yes' ) && function_exists( 'wc_get_cart_url' ) ) ? wc_get_cart_url() : '';
		$cart_label   = '' !== trim( (string) ( $s['atcViewCartLabel'] ?? '' ) ) ? (string) $s['atcViewCartLabel'] : __( 'View cart', 'woocommerce' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch

		ob_start();
		foreach ( $slides as $i => $sl ) :
			$atc       = $sl['atc'];
			$has_price = '' !== (string) $sl['price'] || '' !== (string) $sl['stock'];
			?>
			<div class="ifg__group" data-index="<?php echo (int) $i; ?>">
				<?php if ( $show_counter ) : ?>
					<div class="ifg__count" dir="auto"><?php echo esc_html( sprintf( '%1$s / %2$s', number_format_i18n( $i + 1 ), number_format_i18n( $total ) ) ); ?></div>
				<?php endif; ?>
				<<?php echo esc_html( $title_tag ); ?> class="ifg__title" id="<?php echo esc_attr( 'ifg-t-' . $wid . '-' . $i ); ?>" dir="auto"><?php echo esc_html( $sl['title'] ); ?></<?php echo esc_html( $title_tag ); ?>>
					<?php if ( $sl['rating'] || $sl['sale'] ) : ?>
					<?php // Plain text for screen readers; the stars are decoration. Nothing here is focusable, so the focus order is unchanged. ?>
					<div class="ifg__proof" dir="auto">
						<?php if ( $sl['rating'] ) : $avg = (float) $sl['rating']['avg']; $cnt = (int) $sl['rating']['count']; ?>
							<span class="ifg__rating">
								<span class="ifg__stars" dir="ltr" aria-hidden="true"><span class="ifg__stars-off">★★★★★</span><span class="ifg__stars-on" style="width:<?php echo esc_attr( round( $avg / 5 * 100, 2 ) ); ?>%">★★★★★</span></span>
								<span class="ifg-sr"><?php echo esc_html( sprintf( /* translators: 1: average rating, 2: number of reviews */ __( 'Rated %1$s out of 5 (%2$s reviews)', 'ifg' ), number_format_i18n( $avg, 1 ), number_format_i18n( $cnt ) ) ); ?></span>
								<span class="ifg__rating-count" aria-hidden="true">(<?php echo esc_html( number_format_i18n( $cnt ) ); ?>)</span>
							</span>
						<?php endif; ?>
						<?php if ( $sl['sale'] ) : ?>
							<span class="ifg__sale">
								<span aria-hidden="true"><?php echo esc_html( sprintf( /* translators: %s: discount percentage */ __( '%s%% off', 'ifg' ), number_format_i18n( $sl['sale']['pct'] ) ) ); ?></span>
								<span class="ifg-sr"><?php echo esc_html( sprintf( /* translators: 1: discount percentage, 2: amount saved */ __( '%1$s%% off, you save %2$s', 'ifg' ), number_format_i18n( $sl['sale']['pct'] ), $sl['sale']['save'] ) ); ?></span>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php if ( $has_price || $atc ) : ?>
					<?php // Price + Add to cart sit together. The script animates this block as one piece (it is not split into letters, so the button label can change safely). ?>
					<div class="ifg__buy">
						<?php if ( $has_price ) : ?>
							<div class="ifg__price" dir="auto"><?php echo $sl['price']; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised with wp_kses_post() ?><?php echo $sl['stock'] ? ' <span class="ifg__stock">' . esc_html( $sl['stock'] ) . '</span>' : ''; ?></div>
						<?php endif; ?>
						<?php if ( $atc && $atc['ajax'] ) : ?>
							<button type="button" class="ifg__atc" data-ajax="1" data-product-id="<?php echo (int) $atc['id']; ?>" data-title="<?php echo esc_attr( wp_strip_all_tags( $sl['title'] ) ); ?>">
								<span class="ifg__atc-label" dir="auto"><?php echo esc_html( $atc['text'] ); ?></span><span class="ifg-sr"> — <?php echo esc_html( wp_strip_all_tags( $sl['title'] ) ); ?></span>
							</button>
							<?php if ( $cart_url ) : ?>
								<a class="ifg__viewcart" href="<?php echo esc_url( $cart_url ); ?>" hidden dir="auto"><?php echo esc_html( $cart_label ); ?></a>
							<?php endif; ?>
							<span class="ifg__atc-status ifg-sr" role="status" aria-live="polite" aria-atomic="true"></span>
						<?php elseif ( $atc ) : ?>
							<a class="ifg__atc" data-ajax="0" href="<?php echo esc_url( $atc['url'] ); ?>">
								<span class="ifg__atc-label" dir="auto"><?php echo esc_html( $atc['text'] ); ?></span><span class="ifg-sr"> — <?php echo esc_html( wp_strip_all_tags( $sl['title'] ) ); ?></span>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if ( '' !== trim( (string) $sl['desc'] ) ) : ?>
					<p class="ifg__desc" dir="auto"><?php echo nl2br( esc_html( $sl['desc'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped, then line breaks added ?></p>
				<?php endif; ?>
				<?php // An add-to-cart button already is the action: a second link (even a hidden one) only adds clutter and tab stops. ?>
				<?php if ( ! $atc && ! empty( $sl['url'] ) && ! empty( $s['ctaLabel'] ) ) : ?>
					<a class="ifg__cta" dir="auto" href="<?php echo esc_url( $sl['url'] ); ?>"<?php echo $sl['new_tab'] ? ' target="_blank"' : ''; ?><?php echo $sl['rel'] ? ' rel="' . esc_attr( $sl['rel'] ) . '"' : ''; ?>><?php echo esc_html( $s['ctaLabel'] ); ?></a>
				<?php endif; ?>
			</div>
			<?php
		endforeach;
		return ob_get_clean();
	}

	protected function render() {
		$s      = $this->get_settings_for_display();
		$slides = $this->get_slides( $s );
		$mode   = $this->resolve_display_mode( $s );

		if ( count( $slides ) < 1 ) {
			// Visitors see nothing; the editor gets a hint. (Not tied to the user, so the output stays cacheable.)
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="ifg-empty" style="padding:40px;text-align:center;background:#f3f3f3;color:#666">' .
					esc_html__( 'برای نمایش گالری، حداقل یک اسلاید اضافه کنید یا محصولات ووکامرس را فعال کنید.', 'ifg' ) . '</div>';
			}
			return;
		}

		$wid        = $this->get_id();
		$is_product = $this->is_product_source( $s );
		$show_atc   = $is_product && 'yes' === ( $s['showAtc'] ?? 'yes' );
		$filter_terms = ( $is_product && 'yes' === ( $s['showFilter'] ?? '' ) ) ? $this->get_filter_terms( $s ) : [];
		$has_filter = count( $filter_terms ) > 0;
		$autoplay   = 'yes' === ( $s['autoplay'] ?? '' );

		$config = [
			'overlay'              => in_array( $mode, [ 'overlay', 'page' ], true ) ? 'fixed' : 'contained',
			'wheel'                => 'yes' === ( $s['wheel'] ?? '' ),
			'touch'                => 'yes' === ( $s['touch'] ?? '' ),
			'keyboard'             => 'yes' === ( $s['keyboard'] ?? '' ),
			'invert'               => 'yes' === ( $s['invert'] ?? '' ),
			'sensitivity'          => (float) ( $s['sensitivity'] ?? 1 ),
			'smoothness'           => (float) ( $s['smoothness'] ?? 0.75 ),
			'scrubEase'            => 'power3.out',
			'parallax'             => 'yes' === ( $s['parallax'] ?? '' ),
			'parallaxSpeeds'       => $this->parallax_speeds( $s ),
			'autoplay'             => $autoplay,
			'autoplaySpeed'        => (float) ( $s['autoplaySpeed'] ?? 40 ),
			'autoplayPause'        => 'yes' === ( $s['autoplayPause'] ?? '' ),
			'loader'               => 'yes' === ( $s['loader'] ?? '' ),
			'revealEffect'         => (string) ( $s['revealEffect'] ?? 'fade' ),
			'revealDuration'       => (float) ( $s['revealDuration'] ?? 1 ),
			'revealStagger'        => (float) ( $s['revealStagger'] ?? 0.12 ),
			'captionReveal'        => 'yes' === ( $s['captionReveal'] ?? '' ),
			'captionStagger'       => (float) ( $s['captionStagger'] ?? 0.025 ),
			'openDuration'         => (float) ( $s['openDuration'] ?? 1.2 ),
			'openEase'             => (string) ( $s['openEase'] ?? 'power4.inOut' ),
			'closeDuration'        => (float) ( $s['closeDuration'] ?? 1 ),
			'closeEase'            => (string) ( $s['closeEase'] ?? 'power3.inOut' ),
			'dimOthers'            => 'yes' === ( $s['dimOthers'] ?? '' ),
			'textEffect'           => (string) ( $s['textEffect'] ?? 'chars' ),
			'textStagger'          => (float) ( $s['textStagger'] ?? 0.01 ),
			'textDelay'            => (float) ( $s['textDelay'] ?? 0.8 ),
			'closeOnEsc'           => 'yes' === ( $s['closeOnEsc'] ?? '' ),
			'clickAction'          => $this->click_action( $s ),
			'respectReducedMotion' => 'yes' === ( $s['respectReducedMotion'] ?? 'yes' ),
			'pageMode'             => 'page' === $mode && ! \Elementor\Plugin::$instance->editor->is_edit_mode(),
			'scrollMode'           => 'click' === ( $s['scrollMode'] ?? 'always' ) ? 'click' : 'always',
		];

		// WooCommerce: AJAX add to cart through WooCommerce's own ?wc-ajax=add_to_cart endpoint.
		if ( $show_atc && class_exists( '\WC_AJAX' ) ) {
			$config['cart'] = [
				'url'     => \WC_AJAX::get_endpoint( 'add_to_cart' ),
				'strings' => [
					'adding'   => __( 'Adding…', 'ifg' ),
					'added'    => '' !== trim( (string) ( $s['atcAddedLabel'] ?? '' ) ) ? (string) $s['atcAddedLabel'] : __( 'Added!', 'ifg' ),
					'error'    => __( 'Could not add — try again', 'ifg' ),
					/* translators: %s: product name */
					'announce' => __( '%s was added to your cart.', 'ifg' ),
					/* translators: %s: product name */
					'failed'   => __( '%s could not be added to your cart.', 'ifg' ),
				],
			];
		}

		// Category filter: AJAX re-render of the slides (see IFG_Plugin::ajax_filter()).
		if ( $has_filter ) {
			$doc     = \Elementor\Plugin::$instance->documents->get_current();
			$post_id = $doc ? (int) $doc->get_main_id() : (int) get_the_ID();
			$config['filter'] = [
				'url'     => admin_url( 'admin-ajax.php' ),
				'action'  => 'ifg_filter',
				'post'    => $post_id,
				'widget'  => (string) $wid,
				'current' => (int) get_queried_object_id(),
				'strings' => [
					'one'     => __( '%d product', 'ifg' ),
					'many'    => __( '%d products', 'ifg' ),
					'empty'   => __( 'No products found in this category.', 'ifg' ),
					'error'   => __( 'Could not load the products. Please try again.', 'ifg' ),
				],
			];
		}

		$classes  = [ 'ifg' ];
		$dir_mode = in_array( $s['layoutDir'] ?? '', [ 'ltr', 'rtl' ], true ) ? $s['layoutDir'] : '';
		$is_rtl   = $dir_mode ? 'rtl' === $dir_mode : is_rtl();
		if ( $is_rtl ) {
			$classes[] = 'ifg--rtl';
		}
		if ( $dir_mode ) {
			$classes[] = 'ifg--dir-' . $dir_mode;
		}
		if ( 'bleed' === $mode ) {
			$classes[] = 'ifg--bleed';
		}
		if ( $has_filter ) {
			$classes[] = 'ifg--has-filter';
		}
		$scroll_click = 'click' === ( $s['scrollMode'] ?? 'always' );
		if ( $scroll_click ) {
			$classes[] = 'ifg--scroll-click';
		}

		$wrap_attrs = [
			'id'            => 'ifg-' . $wid,
			'class'         => $classes,
			'data-settings' => wp_json_encode( $config ),
		];
		if ( $dir_mode ) {
			$wrap_attrs['dir'] = $dir_mode;
		}
		// Zooming a "contain" / "fill" image would crop it again, so the zoom is switched off for those modes.
		if ( in_array( $s['imgFit'] ?? 'cover', [ 'contain', 'fill' ], true ) ) {
			$wrap_attrs['style'] = '--ifg-img-scale:1;--ifg-hover-scale:1;';
		}

		$this->add_render_attribute( 'wrap', $wrap_attrs );

		$show_frame    = 'yes' === ( $s['showFrame'] ?? '' );
		$frame_tag     = $this->safe_tag( $s['frameTitleTag'] ?? 'h3', 'h3' );
		$tags          = array_filter( array_map( 'trim', explode( ',', (string) ( $s['frameTags'] ?? '' ) ) ) );
		$frame_link    = $s['frameLinkUrl']['url'] ?? '';
		$frame_rel     = $this->link_rel( $s['frameLinkUrl'] ?? [] );
		$frame_newtab  = ! empty( $s['frameLinkUrl']['is_external'] );
		$gal_id        = 'ifg-gal-' . $wid;
		$end_id        = 'ifg-end-' . $wid;
		$all_label     = '' !== trim( (string) ( $s['filterAllLabel'] ?? '' ) ) ? (string) $s['filterAllLabel'] : __( 'All', 'ifg' );
		$shop_url      = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		?>
		<div <?php echo $this->get_render_attribute_string( 'wrap' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php
			// Progressive enhancement switch. Without JavaScript this never runs, the class stays off and the stylesheet shows a
			// plain responsive grid. (The script also sets the class itself, in case this inline snippet is blocked or delayed.)
			?>
			<script data-cfasync="false" data-no-optimize="1" data-no-defer="1">(function(s){var r=s&&s.parentNode;if(!r||!r.classList||!r.classList.contains('ifg')){r=document.getElementById('<?php echo esc_js( 'ifg-' . $wid ); ?>');}if(r&&r.classList){r.classList.add('ifg--js');}})(document.currentScript);</script>

			<a class="ifg__skip" href="#<?php echo esc_attr( $end_id ); ?>"><?php esc_html_e( 'Skip gallery', 'ifg' ); ?></a>

			<?php if ( $show_frame ) : ?>
				<div class="ifg__frame">
					<div class="ifg__frame-top">
						<<?php echo esc_html( $frame_tag ); ?> class="ifg__frame-title"><?php echo esc_html( $s['frameTitle'] ?? '' ); ?></<?php echo esc_html( $frame_tag ); ?>>
						<?php if ( ! empty( $s['frameLinkText'] ) ) : ?>
							<div class="ifg__frame-links">
								<?php if ( $frame_link ) : ?>
									<a href="<?php echo esc_url( $frame_link ); ?>"<?php echo $frame_newtab ? ' target="_blank"' : ''; ?><?php echo $frame_rel ? ' rel="' . esc_attr( $frame_rel ) . '"' : ''; ?>><?php echo esc_html( $s['frameLinkText'] ); ?></a>
								<?php else : ?>
									<span><?php echo esc_html( $s['frameLinkText'] ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="ifg__frame-bottom">
						<div class="ifg__frame-tags">
							<?php foreach ( $tags as $tag ) : ?>
								<span><?php echo esc_html( $tag ); ?></span>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $has_filter ) : ?>
				<?php // Real links to the category archives: they work without JavaScript. The script turns them into toggle buttons that filter in place. ?>
				<nav class="ifg__filters" aria-label="<?php esc_attr_e( 'Filter products by category', 'ifg' ); ?>">
					<ul class="ifg__filter-list">
						<li><a class="ifg__filter is-active" href="<?php echo esc_url( $shop_url ); ?>" data-term="0"><?php echo esc_html( $all_label ); ?></a></li>
						<?php foreach ( $filter_terms as $term ) :
							$link = get_term_link( $term );
							if ( is_wp_error( $link ) ) {
								continue;
							}
							?>
							<li><a class="ifg__filter" href="<?php echo esc_url( $link ); ?>" data-term="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</nav>
			<?php endif; ?>

			<?php if ( $autoplay ) : ?>
				<?php // WCAG 2.2.2: anything that moves on its own for more than 5 seconds needs a pause control. Revealed by the script. ?>
				<button type="button" class="ifg__pause" hidden data-pause-text="<?php esc_attr_e( 'Pause scrolling', 'ifg' ); ?>" data-play-text="<?php esc_attr_e( 'Resume scrolling', 'ifg' ); ?>"><?php esc_html_e( 'Pause scrolling', 'ifg' ); ?></button>
			<?php endif; ?>

			<div class="ifg__gallery" id="<?php echo esc_attr( $gal_id ); ?>">
				<?php echo $this->render_slides_html( $slides, $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
			</div>

			<div class="ifg__content" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Details', 'ifg' ); ?>" aria-hidden="true">
				<figure class="ifg__preview"><img src="" alt="" class="skip-lazy no-lazy" loading="eager" draggable="false" data-skip-lazy="1" data-no-lazy="1"></figure>
				<div class="ifg__groups">
					<button type="button" class="ifg__back" dir="auto"><?php echo esc_html( $s['backLabel'] ?? '← back [ESC]' ); ?></button>
					<?php echo $this->render_groups_html( $slides, $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
				</div>
			</div>

			<?php if ( $scroll_click ) : ?>
				<div class="ifg__hint" aria-hidden="true" dir="auto"><?php echo esc_html( $s['hintText'] ?? '' ); ?></div>
			<?php endif; ?>

			<?php // Screen-reader announcements for the filter ("12 products") and the cart. ?>
			<div class="ifg__live ifg-sr" role="status" aria-live="polite" aria-atomic="true"></div>
			<span class="ifg__end" id="<?php echo esc_attr( $end_id ); ?>" tabindex="-1"></span>

		</div>
		<?php
	}
}
