<?php
/**
 * GitHub Updater
 *
 * @package   GitHub_Updater
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 */

namespace Fragen\GitHub_Updater;

/**
 * Update a WordPress theme from a GitHub repo.
 *
 * Class      Theme
 * @package   Fragen\GitHub_Updater
 * @author    Andy Fragen
 * @author    Seth Carstens
 * @link      https://github.com/WordPress-Phoenix/whitelabel-framework
 * @author    UCF Web Communications
 * @link      https://github.com/UCF/Theme-Updater
 */
class Theme extends Base {

	/**
	 * Rollback variable
	 *
	 * @var number
	 */
	protected $tag = false;

	/**
	 * Constructor.
	 */
	public function __construct() {

		/**
		 * Get details of git sourced themes.
		 */
		$this->config = $this->get_theme_meta();
		if ( empty( $this->config ) ) {
			return false;
		}
		if ( isset( $_GET['force-check'] ) && '1' === $_GET['force-check'] ) {
			$this->delete_all_transients( 'themes' );
		}

		foreach ( (array) $this->config as $theme ) {
			switch( $theme->type ) {
				case 'github_theme':
					$repo_api = new GitHub_API( $theme );
					break;
				case 'bitbucket_theme':
					$repo_api = new Bitbucket_API( $theme );
					break;
			}

			$this->{$theme->type} = $theme;
			$this->set_defaults( $theme->type );

			if ( $repo_api->get_remote_info( 'style.css' ) ) {
				$repo_api->get_repo_meta();
				$repo_api->get_remote_tag();
				$changelog = $this->get_changelog_filename( $theme->type );
				if ( $changelog ) {
					$repo_api->get_remote_changes( $changelog );
				}
				$theme->download_link = $repo_api->construct_download_link();
			}

			/**
			 * Update theme transient with rollback data.
			 */
			if ( ! empty( $_GET['rollback'] ) && ( $_GET['theme'] === $theme->repo ) ) {
				$this->tag         = $_GET['rollback'];
				$updates_transient = get_site_transient('update_themes');
				$rollback          = array(
					'new_version' => $this->tag,
					'url'         => $theme->uri,
					'package'     => $repo_api->construct_download_link( $this->tag ),
				);
				$updates_transient->response[ $theme->repo ] = $rollback;
				set_site_transient( 'update_themes', $updates_transient );
			}

			/**
			 * Remove WordPress update row in theme row, only in multisite.
			 * Add update row to theme row, only in multisite for WP < 3.8
			 */
			if ( is_multisite() || ( get_bloginfo( 'version' ) < 3.8 ) ) {
				add_action( 'after_theme_row', array( $this, 'remove_after_theme_row' ), 10, 2 );
				if ( ! $this->tag ) {
					add_action( "after_theme_row_$theme->repo", array( $this, 'wp_theme_update_row' ), 10, 2 );
				}
			}

		}

		$this->make_force_check_transient( 'themes' );

		$update = array( 'do-core-reinstall', 'do-core-upgrade' );
		if ( empty( $_GET['action'] ) || ! in_array( $_GET['action'], $update, true ) ) {
			add_filter( 'pre_set_site_transient_update_themes', array( $this, 'pre_set_site_transient_update_themes' ) );
		}

		add_filter( 'themes_api', array( $this, 'themes_api' ), 99, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'upgrader_source_selection' ), 10, 3 );
		add_filter( 'http_request_args', array( $this, 'no_ssl_http_request_args' ), 10, 2 );

		if ( ! is_multisite() ) {
			add_filter('wp_prepare_themes_for_js', array( $this, 'customize_theme_update_html' ) );
		}

		Settings::$ghu_themes = $this->config;
	}

	/**
	 * Put changelog in plugins_api, return WP.org data as appropriate
	 */
	public function themes_api( $false, $action, $response ) {
		if ( ! ( 'theme_information' === $action ) ) {
			return $false;
		}

		/**
		 * Early return $false for adding themes from repo
		 */
		if ( isset( $response->fields ) && ! $response->fields['sections'] ) {
			return $false;
		}

		foreach ( (array) $this->config as $theme ) {
			if ( $response->slug === $theme->repo ) {
				$response->slug         = $theme->repo;
				$response->name         = $theme->name;
				$response->homepage     = $theme->uri;
				$response->version      = $theme->remote_version;
				$response->sections     = $theme->sections;
				$response->description  = implode( "\n", $theme->sections );
				$response->author       = $theme->author;
				$response->preview_url  = $theme->theme_uri;
				$response->requires     = $theme->requires;
				$response->tested       = $theme->tested;
				$response->downloaded   = $theme->downloaded;
				$response->last_updated = $theme->last_updated;
				$response->rating       = $theme->rating;
				$response->num_ratings  = $theme->num_ratings;
			}
		}
		add_action( 'admin_head', array( $this, 'fix_display_none_in_themes_api' ) );

		return $response;
	}

	/**
	 * Fix for new issue in 3.9 :-(
	 */
	public function fix_display_none_in_themes_api() {
		echo '<style> #theme-installer div.install-theme-info { display: block !important; }  </style>';
	}

	/**
	 * Add custom theme update row, from /wp-admin/includes/update.php
	 *
	 * @author Seth Carstens
	 */
	public function wp_theme_update_row( $theme_key, $theme ) {
		$current            = get_site_transient( 'update_themes' );
		$themes_allowedtags = array(
				'a'       => array( 'href' => array(), 'title' => array() ),
				'abbr'    => array( 'title' => array() ),
				'acronym' => array( 'title' => array() ),
				'code'    => array(),
				'em'      => array(),
				'strong'  => array(),
			);
		$theme_name         = wp_kses( $theme['Name'], $themes_allowedtags );
		$wp_list_table      = _get_list_table( 'WP_MS_Themes_List_Table' );
		$install_url        = self_admin_url( "theme-install.php" );
		$details_url = add_query_arg(
				array(
					'tab' => 'theme-information',
					'theme' => $theme_key,
					'TB_iframe' => 'true',
					'width' => 270,
					'height' => 400
				),
				$install_url );

		if ( isset( $current->up_to_date[ $theme_key ] ) ) {
			$rollback      = $current->up_to_date[ $theme_key ]['rollback'];
			$rollback_keys = array_keys( $rollback );
			echo '<tr class="plugin-update-tr"><td colspan="' . $wp_list_table->get_column_count() . '" class="plugin-update colspanchange"><div class="update-message update-ok">';
			_e( 'Theme is up-to-date!&nbsp;', 'github-updater' );
			if ( count( $rollback ) > 0 ) {
				array_shift( $rollback_keys ); //don't show newest tag, it should be release version
				_e( '<strong>Rollback to:&nbsp;</strong>', 'github-updater' );
				// display last three tags
				for ( $i = 0; $i < 3 ; $i++ ) {
					$tag = array_shift( $rollback_keys );
					if ( empty( $tag ) ) {
						break;
					}
					if ( $i > 0 ) {
						echo ", ";
					}

					printf( '<a href="%s%s">%s</a>', wp_nonce_url( self_admin_url( 'update.php?action=upgrade-theme&theme=' ) . $theme_key, 'upgrade-theme_' . $theme_key ), '&rollback=' . urlencode( $tag ), $tag);
				}
			} else {
				_e( 'No previous tags to rollback to.', 'github-updater' );
			}
		}

		if ( isset( $current->response[ $theme_key ] ) ) {
			$r = $current->response[ $theme_key ];
			echo '<tr class="plugin-update-tr"><td colspan="' . $wp_list_table->get_column_count() . '" class="plugin-update colspanchange"><div class="update-message">';
			if ( empty( $r['package'] ) ) {
				printf(
					__( 'GitHub Updater shows a new version of %1$s available.&nbsp;', 'github-updater' ) . '<a href="%2$s" class="thickbox" title="%3$s">' . __( 'View version %4$s details', 'github-updater' ) . '</a>. <em>' . __( 'Automatic update is unavailable for this theme.', 'github-updater' ) . '</em>',
					$theme_name,
					esc_url( $details_url ),
					esc_attr( $theme_name ),
					$r['new_version']
				);
			} else {
				printf(
					__( 'GitHub Updater shows a new version of %1$s available.&nbsp;', 'github-updater' ) . __( '<a %2$s>View version %3$s details</a> or <a href="%4$s">update now</a>.', 'github-updater' ),
					$theme_name,
					sprintf(
						'href="%1$s" class="thickbox" title="%2$s"',
						esc_url( $details_url ),
						esc_attr( $theme_name )
					),
					$r['new_version'],
					wp_nonce_url( self_admin_url( 'update.php?action=upgrade-theme&theme=' ) . $theme_key, 'upgrade-theme_' . $theme_key )
				);
			}

			do_action( "in_theme_update_message-$theme_key", $theme, $r );
		}
		echo '</div></td></tr>';
	}

	/**
	 * Remove default after_theme_row_$stylesheet
	 *
	 * @author @grappler
	 * @param $theme_key
	 * @param $theme
	 */
	public static function remove_after_theme_row( $theme_key, $theme ) {
		$repositories = array( 'GitHub Theme URI', 'Bitbucket Theme URI' );
		foreach ( (array) $repositories as $repository ) {
			$repo_uri = $theme->get( $repository );
			if ( empty( $repo_uri ) ) {
				continue;
			}

			remove_action( "after_theme_row_$theme_key", 'wp_theme_update_row', 10 );
		}
	}

	/**
	 * Call update theme messaging if needed for single site installation
	 *
	 * @author Seth Carstens
	 * @param $prepared_themes
	 *
	 * @return mixed
	 */
	public function customize_theme_update_html( $prepared_themes ) {
		foreach ( (array) $this->config as $theme ) {
			if ( empty( $prepared_themes[ $theme->repo ] ) ) {
				continue;
			}

			if ( ! empty( $prepared_themes[ $theme->repo ]['hasUpdate'] ) ) {
				$prepared_themes[ $theme->repo ]['update'] = $this->_append_theme_actions_content( $theme );
			} else {
				$prepared_themes[ $theme->repo ]['description'] .= $this->_append_theme_actions_content( $theme );
			}
		}

		return $prepared_themes;
	}

	/**
	 * Create theme update messaging
	 *
	 * @author Seth Carstens
	 * @param object $theme
	 * @return string (content buffer)
	 */
	private function _append_theme_actions_content( $theme ) {

		$details_url            = self_admin_url( "theme-install.php?tab=theme-information&theme=$theme->repo&TB_iframe=true&width=270&height=400" );                
		$theme_update_transient = get_site_transient( 'update_themes' );

		/**
		 * If the theme is outdated, display the custom theme updater content.
		 * If theme is not present in theme_update transient response ( theme is not up to date )
		 */
		if ( empty( $theme_update_transient->up_to_date[$theme->repo] ) ) {
			$update_url = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-theme&theme=' ) . urlencode( $theme->repo ), 'upgrade-theme_' . $theme->repo );
			ob_start();
			?>
			<strong><br /><?php _e('There is a new version of&nbsp;', 'github-updater' ); echo $theme->name; _e( '&nbsp;available now.', 'github-updater' ); ?> <a href="<?php echo $details_url; ?>" class="thickbox" title="<?php echo $theme->name; ?>"><?php _e( '&nbsp;View version&nbsp;', 'github-updater' ); echo $theme->remote_version; _e( '&nbsp;details', 'github-updater' ); ?></a><?php _e( '&nbsp;or&nbsp;', 'github-updater' ); ?><a href="<?php echo $update_url; ?>"><?php _e( 'update now', 'github-updater' ); ?></a>.</strong>
			<?php

			return trim( ob_get_clean(), '1' );
		} else {
			/**
			 * If the theme is up to date, display the custom rollback/beta version updater
			 */
			ob_start();
			$rollback_url = sprintf( '%s%s', wp_nonce_url( self_admin_url( 'update.php?action=upgrade-theme&theme=' ) . urlencode( $theme->repo ), 'upgrade-theme_' . $theme->repo ), '&rollback=' );

			?>
			<p><?php _e( 'Current version is up to date. Try', 'github-updater' ); ?> <a href="#" onclick="jQuery('#ghu_versions').toggle();return false;"><?php _e( 'another version?', 'github-updater' ); ?></a></p>
			<div id="ghu_versions" style="display:none; width: 100%;">
				<select style="width: 60%;" 
					onchange="if(jQuery(this).val() != '') {
						jQuery(this).next().show(); 
						jQuery(this).next().attr('href','<?php echo $rollback_url ?>'+jQuery(this).val()); 
					}
					else jQuery(this).next().hide();
				">
				<option value=""><?php _e( 'Choose a Version&#8230;', 'github-updater' ); ?></option>
				<option><?php echo $theme->branch; ?></option>
				<?php foreach ( array_keys( $theme_update_transient->up_to_date[ $theme->repo ]['rollback'] ) as $version ) { echo'<option>' . $version . '</option>'; }?></select>
				<a style="display: none;" class="button-primary" href="?"><?php _e( 'Install', 'github-updater' ); ?></a>
			</div>
			<?php

			return trim( ob_get_clean(), '1' );
		}
	}

	/**
	 * Hook into pre_set_site_transient_update_themes to update
	 *
	 * Finds newest tag and compares to current tag
	 *
	 * @param array $data
	 * @return array|object
	 */
	public function pre_set_site_transient_update_themes( $data ) {

		foreach ( (array) $this->config as $theme ) {
			if ( empty( $theme->uri ) ) {
				continue;
			}
			
			$update = array(
				'new_version' => $theme->remote_version,
				'url'         => $theme->uri,
				'package'     => $theme->download_link,
			);

			if ( $this->can_update( $theme ) ) {
				$data->response[ $theme->repo ] = $update;
			} else { // up-to-date!
				$data->up_to_date[ $theme->repo ]['rollback'] = $theme->rollback;
				$data->up_to_date[ $theme->repo ]['response'] = $update;
			}
		}

		return $data;
	}

}
