<?php

if (!defined('ABSPATH')) exit;



class HDS3_Admin_Branding {



    public function __construct() {

        add_action('admin_menu', [$this,'menu'], 55);

        add_action('admin_init', [$this,'register_settings']);

    }



    public function menu() {

        add_submenu_page(

            'hds3_center',

            'Branding',

            'Branding',

            'manage_options',

            'hds3_branding',

            [$this,'render']

        );

    }



    public function register_settings() {

        register_setting('hds3_branding', 'hds3_brand_logo_url', ['type'=>'string','sanitize_callback'=>'esc_url_raw','default'=>'']);

    }



    public function render() {

        ?>

        <div class="wrap">

            <h1>Identyfikacja wizualna Heterodyna</h1>

            <form method="post" action="options.php">

                <?php settings_fields('hds3_branding'); ?>

                <table class="form-table" role="presentation">

                    <tr>

                        <th scope="row"><label for="hds3_brand_logo_url">Logo w pasku</label></th>

                        <td>

                            <input type="url" class="regular-text" id="hds3_brand_logo_url" name="hds3_brand_logo_url" value="<?php echo hds3_safe_attr(get_option('hds3_brand_logo_url','')); ?>" placeholder="Wklej URL obrazka (Media Library)"/>

                            <p class="description">Wklej URL do logo (np. z Biblioteki Mediów). Jeśli pole jest puste, zostanie wykorzystane logo Heterodyna dołączone do wtyczki.</p>

                        </td>

                    </tr>

                </table>

                <?php submit_button('Zapisz'); ?>

            </form>

        </div>

        <?php

    }

}



new HDS3_Admin_Branding();

