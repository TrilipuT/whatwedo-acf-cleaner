<?php

namespace whatwedo\AcfCleaner;

/**
 * WP Hooks
 *
 * @since      1.0.0
 * @package    wwd-acf-cleaner
 */

class WP
{
    private $actionNonceName = WWDACFCLEANER_NAME . '-action-nonce';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'addAdminMenu']);

        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_filter('script_loader_tag', [$this, 'addScriptAttribute'], 10, 3);

        add_action('wp_ajax_singleDiscovery', [$this, 'singleDiscovery']);
        add_action('wp_ajax_batchDiscovery', [$this, 'batchDiscoveryRequest']);
        add_action('wp_ajax_batchCleanup', [$this, 'batchCleanupRequest']);
        add_action('wp_ajax_singleCleanup', [$this, 'singleCleanupRequest']);

        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);

    }

	/**
	 * Register meta box(es).
	 */
	function register_meta_boxes() {
		global $post;
		//TODO: move this to ajax call on-demand.
		$discovery = new Discovery( $post->ID, true );
		$unused    = $discovery->getUnusedData();
		if ( $unused ) {
			add_meta_box( 'unused-acf-fields', 'Unused ACF Fields', [ $this, 'metabox_content' ] );
		}
	}

	/**
	 * Meta box display callback.
	 *
	 * @param WP_Post $post Current post object.
	 */
	function metabox_content( $post ) {
		$postId = $post->ID;
		$discovery = new Discovery( $postId, true );
		$unused    = $discovery->getUnusedData();
		echo "<div id='acf_cleanup_data'>";
		foreach ( $unused as $name => $key ) {
			$field = trim( $name, "_" );
			echo "<div style='display: inline-block;width: 19%' title='$key'>$field</div>
<input style='width: 80%;height: 2em' disabled value='" . sanitize_text_field( print_r( esc_html(get_field( $field, $postId )),
					true ) ) . "'><br>";
		}
		echo "<button class='button secondary' id='acf_cleanup_action'>Clean data</button>";
		echo "<script>jQuery('#acf_cleanup_action').on('click',function(e){
    			e.preventDefault();
                jQuery(e.currentTarget).addClass('disabled')
			    if(confirm('Are you sure?')){
		            wp.ajax.send('singleCleanup', {
				        data: { postId: " . $postId . " },
				        success: function( response ) {
                            jQuery('#acf_cleanup_data').empty().html('Success! <br> Removed '+response.count+' fields');
				            jQuery(e.currentTarget).removeClass('disabled');
				        },
                        error: function( response ) {
                            alert(response)
				            jQuery(e.currentTarget).removeClass('disabled');
				        }
					})
                }
			})</script>";
		echo "</div>";
		// Display code/markup goes here. Don't forget to include nonces!
	}

    public function addAdminMenu()
    {
        $this->tool_menu_id = add_management_page(
            __('WWD ACF Cleaner by whatwedo', 'wwdac'),
            __('ACF Cleaner', 'wwdac'),
            'manage_options',
            'wwd-acf-cleaner',
            [$this, 'managementInterfaceRender']
        );
    }

    public function managementInterfaceRender()
    {

        echo '<div id="wwdac-app"></div>';

        /*
        // Test hardcoded post on server side
        $postId = 2832;
        $isDry = true;
        $discovery = new Discovery($postId, $isDry);
        print_r($discovery->getUnusedData());
        */
    }

    /*
        Enqueue admin script
     */

    public function enqueueAdminAssets()
    {
        if (get_current_screen()->id === 'tools_page_wwd-acf-cleaner') {
            wp_enqueue_script('vuejs', WWDACFCLEANER_DIR_URL . 'assets/vendors/vue.global.prod.js', [], true);
            wp_register_script('wwdac-vuejs', WWDACFCLEANER_DIR_URL . 'assets/wwd-acf-cleaner.js', 'vuejs', true);

            wp_localize_script('wwdac-vuejs', 'wwdacData', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => 'discoverPost',
                'nonce' => wp_create_nonce($this->actionNonceName),
                'postTypes' => Data::getAllCustomPostTypes(),
                'posts' => (new Data)->batchDiscovery(['post', 'page']),
            ]);
            wp_enqueue_script('wwdac-vuejs');

            wp_enqueue_style('tailwind-css', WWDACFCLEANER_DIR_URL . 'assets/vendors/tailwind.min.css', [], true);
        }
    }

    public function addScriptAttribute($tag, $handle, $src)
    {
        if ('wwdac-vuejs' !== $handle) {
            return $tag;
        }
        $tag = str_replace(' src', ' type="module" src', $tag);

        return $tag;
    }

    public function singleDiscovery()
    {
        Helper::checkNonce($this->actionNonceName);

        $postId = (int) $_POST['postId'];
        $data = (new Data())->singleDiscovery($postId);

        Helper::returnAjaxData($data);
    }

    public function batchDiscoveryRequest()
    {
        Helper::checkNonce($this->actionNonceName);

        $params = $this->checkParams();
        $batchData = (new Data())->batchDiscovery($params['postType'], $params['paged'], true);

        Helper::returnAjaxData($batchData);
    }

    public function batchCleanupRequest()
    {
        Helper::checkNonce($this->actionNonceName);

        $params = $this->checkParams();
        $batchData = (new Data())->batchDiscovery($params['postType'], $params['paged'], false);

        Helper::returnAjaxData($batchData);
    }

	public function singleCleanupRequest()
	{
		$postId    = $_POST['postId'];
		$isDry     = false;
		$discovery = new Discovery( $postId, $isDry );

		$count = count( $discovery->cleanAcfUnusedData() );
		if ( $count ) {
			wp_send_json_success( [ 'count' => count( $discovery->cleanAcfUnusedData() ) ] );
		}

		wp_send_json_error( 'Someting went wrong...' );
	}

    private function checkParams()
    {
        $postType = array_map('sanitize_text_field', explode(',', $_POST['postType']));
        foreach ($postType as $singlePostType) {
            if (!post_type_exists($singlePostType)) {
                unset($postType[$singlePostType]);
            }
        }
        $paged = (int) $_POST['paged'];

        return [
            'postType' => $postType,
            'paged' => $paged,
        ];
    }
}
