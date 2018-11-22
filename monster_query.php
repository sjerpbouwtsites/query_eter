<?php

class WP_monster_model {    

// de post objecten worden gestript en verrijkt.
// indien je standaard wp postdata mist, voeg de sleutel toen aan $extra_wp_post_data

public function __construct($posts_verz, $recursie = true, $extra_wp_post_data = array()){
    // Van de gerelateerde posts wordt standaard ook extra data gezocht; van hun gerelateerde posts niet.
    $this->pv = $posts_verz;
    $this->recursie = $recursie; 
    $this->extra_wp_post_data = $extra_wp_post_data; 
    $this->res_pv = array_map(array($this, 'pv_map'), $this->pv);
    $this->ppp = false;
}

public function resultaat(){
    return $this->res_pv;
}

public function posts_per_posttype(){

    // zet extra data op de postobject, nl
    // de rel_posts uitgesorteerd per post_type

    if ($this->ppp) return; // het hoeft maar één keer. 
    foreach ($this->resultaat() as $post) {

        $rel_pt_en_eigen_pt = array_merge($post->meta['rel_post_types'], array($post->post_type));

        // voor iedere posttype, 
        // geef die posts in rel_posts die deze posttype hebben.
        foreach ($rel_pt_en_eigen_pt as $pt) {
            $post->$pt = array_filter(
                $post->rel_posts, 
                function($rel_post) use ($pt){
                    return $pt === $rel_post->post_type;
                }
            );                
        }
    }

    $this->ppp = true;

}

private function pv_map($post) {

    global $wp_taxonomies;

    // de taxonomieen geregistreerd op deze posttype
    $tax_r = get_post_taxonomies( $post );

    // we maken er een associatieve array van
    $tax_slug_waarden = array_combine($tax_r, $tax_r);

    // vorm: tax_naam_slug => array(tax_slug_waarde)
    $tax_waarden = $this->tax_waarden($tax_slug_waarden, $post);
    
    // bevat per taxonomie alle posttypes geassocieerd met alle taxonomieen
    // zitten dus mogelijk dubbels bij
    // de eigen posttype is uitgesloten
    $rel_pt_rauw = $this->rel_pt_rauw($tax_slug_waarden, $wp_taxonomies, $post);
    
    // we pakken alleen de waarden (de posttypes) en slaan dit plat tot array('page', 'recept', 'project')
    $rel_post_types = $this->sla_verzameling_plat(
        array_values($rel_pt_rauw)
    );

    // omdat natuurlijk ook posts van de eigen posttype geassocieerd kunnen zijn, 
    // voegen we tbv de query die weer toe.
    $eigen_pt_en_rel_pt = array_merge(
        $rel_post_types,
        array($post->post_type)
    );

    // alle gerelateerde posts + hun gerelateerde posts(als recursie != false)
    $rel_posts = $this->rel_posts($tax_waarden, $eigen_pt_en_rel_pt, $post->ID);

    // WP post object gestript tot kern.
    $r = array(
        'ID'                => $post->ID,
        'post_content'      => $post->post_content,
        'post_title'        => $post->post_title,
        'rel_posts'         => $rel_posts,
        'post_type'         => $post->post_type,
        'meta'              => array(
            'tax'               => array(
                'tax_slugs'         => array_keys($tax_slug_waarden),
                'waarden_per_tax'   => $tax_waarden
            ),
            'rel_post_types'    => $rel_post_types,                
        )
    );

    // indien gewenst alsnog standaard postdata velden
    if (!empty($this->extra_wp_post_data)) {
        foreach ($this->extra_wp_post_data as $veldnaam) {
            $r[$veldnaam] = $post->$veldnaam;
        }
    }

    // acf velden worden direct op het post object gezet.
    if ($acf_velden = get_fields($post->ID)) {
        foreach ($acf_velden as $k=>$v) {
            $r[$k] = $v;
        }
    }

    return (object) $r; 
}

private function sla_verzameling_plat($array){

    // nutsfunctie. maakt van een geneste verzameling een platte verzameling.
    
    $result = array();     
    foreach($array as $key=>$value) {
            if(is_array($value)) {
                $result = $result + $this->sla_verzameling_plat($value);
            }
            else {
                $result[$key] = $value;
            }
        }
    return $result;
                
}

private function rel_posts($tax_waarden, $eigen_pt_en_rel_pt, $deze_post_id) {

    // haal de gerelateerde posts op.

    // eerste de taxonomie query maken.
    // we willen alle mogelijke posts die
    // voldoen aan één van de taxonomiewaarden.
     $tax_query = array_map (
        function ($tax_naam, $waarde) {
            return array(
                'taxonomy' => $tax_naam,
                'field' => 'slug',
                'terms' => $waarde,                 
            );
        }, 
        array_keys($tax_waarden), 
        $tax_waarden
         
    );

    // tax query is van zichzelf uitsluitend, dit maakt het insluitend.
    $tax_query['relation'] = 'OR';

    $query = array(
        'post_type'         => $eigen_pt_en_rel_pt, // dat is dus een array met posttypes
        'posts_per_page'    => -1,
        'tax_query'         => $tax_query,
        'operator'          => 'IN',
        'post__not_in'      => array($deze_post_id) // post is niet zijn eigen gerelateerde post.
    );

    $posts = get_posts($query);

    // van de rel posts wordt ook de data zoals ACF en 
    // de gerelateerde posts opgehaald.
    // van hun gerelateerde post niet meer. 
    if ($this->recursie) {
        $lokaal_monster = new WP_monster_model($posts, false);
        return $lokaal_monster->resultaat();
    } else {
        return $posts;
    }

}

private function filter_pt_rauw($rel_pt_rauw) {

    // filter lengteloze er uit.
    // en geloof ook dubbels

    return array_values(
        array_filter(
            $rel_pt_rauw, 
            function($rel_lijst){
                return count($rel_lijst);
            }            
        )
    );
}

private function rel_pt_rauw($tax_slug_waarden, $wp_taxonomies, $post) {

    // maakt per taxonomie een lijst met gerelateerde posttypes
    // posttype van de post zelf is uitgesloten

    return array_map(function($slug) use ($wp_taxonomies, $post){
        return array_filter($wp_taxonomies[$slug]->object_type, function($post_type) use ($post){
            return $post_type !== $post->post_type;
        });
    }, $tax_slug_waarden);
}

private function tax_waarden($tax_slug_waarden, $post){

    // wp_get_post_terms geeft veel te veel
    // we willen alleen de slug hebben.
    // maakt per taxonomie en verzameling van waarden.

    return array_map(function($slug) use ($post){
        return array_map(function($tax_waarde){
            return $tax_waarde->slug;
        }, wp_get_post_terms( $post->ID, $slug ));
    }, $tax_slug_waarden);
}

}

$voorbeeld = new WP_monster_model($wp_query->posts, true);
$voorbeeld->posts_per_posttype();