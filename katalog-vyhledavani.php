<?php
/**
 * Plugin Name: Katalog - Vyhledávání
 * Description: Plugin upravující vyhledávání v katalogu firem.
 * Version: 0.1.0
 * Author: Ondřej Doněk
 * Author URI: https://ondrejd.com/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.en.html
 * Requires at least: 4.9
 * Requires PHP: 5.6
 * Tested up to: 4.9.6
 * Text Domain: katalog-vyhledavani
 * Domain Path: /languages/
 *
 * @author Ondřej Doněk <ondrejd@gmail.com>
 * @package katalog-vyhledavani
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if ( ! function_exists( 'katalog_nastaveni_omezeni_vyhledavani' ) ) :
    /**
     * Přidá nastavení do customizeru.
     * @param WP_Customize_Manager $wp_customize
     * @return void
     * @since 0.1.0
     */
    function katalog_nastaveni_omezeni_vyhledavani( $wp_customize ) {
        include plugin_dir_path( __FILE__ ) . 'Katalog_Rocniky_Dropdown.class.php';

        // Vybrat rocník pro 
	    $wp_customize->add_setting( 'katalog_nastaveni_vyhledavani_rocnik',
            array( 'default' => '' )
        );
	    $wp_customize->add_control( new Katalog_Rocniky_Dropdown(
            $wp_customize,
            'katalog_nastaveni_vyhledavani_rocnik',
            array(
		        'label'       => 'Omezení ročníku ve vyhledávání',
                'description' => 'Můžete omezit vyhledávání v katalogu jen pro vybraný ročník nebo pro všechny ročníky. Pokud vyberete "Žádný", pak budou firmy <strong>úplně vyjmuty z vyhledávání</strong>.',
		        'section'     => 'katalog_nastaveni_sekce'
	        )
        ) );

	    $wp_customize->add_setting( 'katalog_nastaveni_vyhledavani_stranka',
            array( 'default' => 0 )
        );
	    $wp_customize->add_control('katalog_nastaveni_vyhledavani_stranka', array(
		    'label'       => 'Omezení stránky ve vyhledávání.',
		    'section'     => 'katalog_nastaveni_sekce',
		    'type'        => 'dropdown-pages',
            'description' => 'Vyberte stránku, na kterou chcete funkčnost pluginu omezit, nebo nevyberte žádnou pro zachování funkčnosti na celý web.',
	    ));

        // V případě nalezení jednoho záznamu z katalogu přesměrovat rovnou 
        // na detail?
	    $wp_customize->add_setting( 'katalog_nastaveni_vyhledavani_presmerovani',
            array( 'default' => 1 )
        );
	    $wp_customize->add_control('katalog_nastaveni_vyhledavani_presmerovani', array(
		    'label'       => 'Při nalezení pouze jednoho záznamu přesměrovat na jeho detail?',
		    'section'     => 'katalog_nastaveni_sekce',
		    'type'        => 'checkbox',
            'description' => 'Pokud bude nalezena jen jedna firma, bude zobrazen její detail místo stránky s výsledky vyhledávání.',
	    ));
    }
endif;
add_action( 'customize_register', 'katalog_nastaveni_omezeni_vyhledavani', 99 );


if ( ! function_exists( 'katalog_filtr_vyhledavani' ) ) :
    /**
     * Filtr pro vyhledávání.
     * @param WP_Query $query
     * @return WP_Query
     * @since 0.1.0
     */
    function katalog_filtr_vyhledavani( $query ) {

        // Tato funkcnost plati jen pro vyhledavani na front-endu
        if ( ! $query->is_search || is_admin() ) {
            return $query;
        }

        // Ziskame nastaveni
        $aktualni_rok = get_theme_mod( 'katalog_nastaveni_rok' );
        $nastaveni_vyhledavani_rocnik = get_theme_mod( 'katalog_nastaveni_vyhledavani_rocnik' );
        $nastaveni_vyhledavani_stranka = get_theme_mod( 'katalog_nastaveni_vyhledavani_stranka' );
        $omezit_vyhledavani = false; // Omezit vyhledavani na vybrany rocnik?
        $zrusit_vyhledavani = false; // Zrusit vyhledavani ve firmach uplne?

        if ( (int) $nastaveni_vyhledavani_rocnik > 0 ) {
            $omezit_vyhledavani = true;
        }
        elseif ( empty( $nastaveni_vyhledavani_rocnik ) ) {
            $zrusit_vyhledavani = true;
        }

        // Zrusime vyhledavani ve firmach uplne
        if ( $zrusit_vyhledavani === true ) {

            // To udelame tak, ze vyjmeme firmy z post_types v query
            $post_types = get_post_types( array( 'public' => true ) );
            unset( $post_types['katalog'] );
            $post_types = array_keys( $post_types );

            $query->set( 'post_type', $post_types );
        }

        // Omezime vysledky ve firmach jen na aktualni rocnik
        elseif ( $omezit_vyhledavani === true ) {

            // Vsechny firmy ve vybranem rocniku
            $firmy = get_posts( array(
                'posts_per_page' => -1,
                'post_type' => 'katalog',
                'tax_query' => array(
	                array(
		                'taxonomy' => 'rocniky',
		                'field'    => 'term_id',
		                'terms'    => $nastaveni_vyhledavani_rocnik,
	                ),
                ),
            ) );

            // Ziskame jejich ID
            $firmy_in = array();
            for ( $i = 0; $i < count( $firmy ); $firmy_in[] = $firmy[$i++]->ID );

            // Upravime vyhledavaci query
            $query->set( 'post__in', $firmy_in );
        }

        // TODO Nedělat žádné změny, pokud je nastaveno `$nastaveni_vyhledavani_stranka`
        //      a zdrojový request pochází od ní!

        // Vratime upravenou query
        return $query;
    }
endif;
add_filter( 'pre_get_posts', 'katalog_filtr_vyhledavani' );


if ( ! function_exists( 'katalog_vyhledavani_presmerovani' ) ) :
    /**
     * Zajistí přesměrování na detail firmy, pokud je ve výsledcích vyhledávání 
     * jen jedna jedinná.
     * @global WP_Query $wp_query
     * @return void
     * @since 0.1.0
     */
    function katalog_vyhledavani_presmerovani() {
        global $wp_query;

        // Pokud není vyhledávání, nebo je admin, nedělat nic
        if ( ! is_search() || is_admin() ) {
            return;
        }

        // Je přesměrování povoleno? (Není? Tak pryč.)
        $nastaveni_vyhledavani_presmerovani = get_theme_mod( 'katalog_nastaveni_vyhledavani_presmerovani' );
        if ( $nastaveni_vyhledavani_presmerovani != '1' ) {
            return;
        }

        // Je to jen jeden výsledek a navíc firma?
        if ( $wp_query->post_count == 1 ) {
            if ( $wp_query->posts[0]->post_type == 'katalog' ) {
                wp_redirect( get_permalink( $wp_query->posts[0]->ID ) );
                die;
            }
        }

    }
endif;
add_action( 'template_redirect', 'katalog_vyhledavani_presmerovani' );

