<?php
defined( 'ABSPATH' ) || exit;

/* ============================================================
   Shared portal UI — members-only subnav band, editorial hero,
   and avatar helpers. Introduced with the portal redesign (1.5.0).

   The theme renders the public site header; these helpers add the
   second "members only" navigation band and the Events-style hero
   underneath it, exactly as approved in the canvas mockups.
   ============================================================ */

/* Pages that belong to the portal (slug => nav item) */
function clfa_portal_pages() {
	return array(
		'home'      => array( 'slug' => 'alumni-home',      'label' => __( 'Home', 'clf-alumni' ) ),
		'directory' => array( 'slug' => 'alumni-directory', 'label' => __( 'Directory', 'clf-alumni' ) ),
		'events'    => array( 'slug' => 'alumni-events',    'label' => __( 'Events', 'clf-alumni' ) ),
		'mentors'   => array( 'slug' => 'alumni-mentors',   'label' => __( 'Mentors', 'clf-alumni' ) ),
		'board'     => array( 'slug' => 'alumni-board',     'label' => __( 'Opportunities', 'clf-alumni' ) ),
		'profile'   => array( 'slug' => 'alumni-profile',   'label' => __( 'My Profile', 'clf-alumni' ) ),
	);
}

/* Body class so plugin CSS can restyle the theme shell on portal pages
   (hide the navy page-title band, release the 900px content column). */
function clfa_portal_body_class( $classes ) {
	if ( is_page() && clfa_is_member() ) {
		$post = get_post();
		if ( $post && false !== strpos( $post->post_content, '[clf_alumni_' ) && false === strpos( $post->post_content, '[clf_alumni_login]' ) ) {
			$classes[] = 'clfa-portal';
		}
	}
	return $classes;
}
add_filter( 'body_class', 'clfa_portal_body_class' );

/* Portal pages are members-only and personalized — they must never be
   served from a page cache (host/CDN). Without this, a member can see a
   stale copy of the directory, mentor roster, etc. from before their
   latest profile save. */
function clfa_portal_nocache() {
	if ( ! is_page() ) {
		return;
	}
	$post = get_post();
	if ( $post && false !== strpos( $post->post_content, '[clf_alumni_' ) ) {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}
add_action( 'template_redirect', 'clfa_portal_nocache', 1 );

/* ---- Members-only subnav band ---- */
function clfa_portal_nav( $active = '' ) {
	$out = '<div class="clfa-subnav"><span class="clfa-subnav-label">' . esc_html__( 'Members only', 'clf-alumni' ) . '</span><nav class="clfa-subnav-nav" aria-label="' . esc_attr__( 'Members navigation', 'clf-alumni' ) . '">';
	foreach ( clfa_portal_pages() as $key => $item ) {
		$out .= '<a class="' . ( $key === $active ? 'is-active' : '' ) . '" href="' . esc_url( clfa_page_url( $item['slug'] ) ) . '">' . esc_html( $item['label'] ) . '</a>';
	}
	$out .= '<a class="clfa-subnav-out" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Sign out', 'clf-alumni' ) . '</a>';
	$out .= '</nav></div>';
	return $out;
}

/* ---- Events-style editorial hero ----
   $title_html is trusted plugin-built markup (em/br), escape inputs upstream. */
function clfa_portal_hero( $kicker, $title_html, $copy = '', $meta = array(), $class = '' ) {
	$out  = '<section class="clfa-hero' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '"><div class="clfa-hero-inner"><div>';
	$out .= '<div class="clfa-hero-kicker">' . wp_kses( $kicker, array( 'span' => array() ) ) . '</div>';
	$out .= '<h1>' . wp_kses( $title_html, array( 'em' => array(), 'br' => array() ) ) . '</h1></div>';
	if ( $copy || $meta ) {
		$out .= '<div class="clfa-hero-copy">';
		if ( $copy ) {
			$out .= '<p>' . esc_html( $copy ) . '</p>';
		}
		if ( $meta ) {
			$out .= '<div class="clfa-hero-meta">';
			foreach ( $meta as $m ) {
				$out .= '<span>' . esc_html( $m ) . '</span>';
			}
			$out .= '</div>';
		}
		$out .= '</div>';
	}
	$out .= '</div></section>';
	return $out;
}

/* ---- Avatars: photo if uploaded, otherwise tinted initials circle ---- */
function clfa_avatar_tone( $user_id ) {
	$tones = array( '#c77d63', '#718a87', '#b6a081', '#8b7890', '#c3a37b', '#70859a', '#ad786c', '#7e9789', '#a56758', '#9b8368' );
	return $tones[ (int) $user_id % count( $tones ) ];
}

function clfa_avatar( $user_id, $class = '' ) {
	$file = get_user_meta( $user_id, 'clfa_photo_file', true );
	if ( $file ) {
		$src = add_query_arg( 'clfa_photo', (int) $user_id, home_url( '/' ) );
		return '<img class="clfa-avatar ' . esc_attr( $class ) . '" src="' . esc_url( $src ) . '" alt="" loading="lazy">';
	}
	$user     = get_userdata( $user_id );
	$initials = '';
	if ( $user ) {
		$initials = strtoupper( mb_substr( $user->first_name ?: $user->display_name, 0, 1 ) . mb_substr( $user->last_name, 0, 1 ) );
	}
	return '<span class="clfa-avatar clfa-avatar-initials ' . esc_attr( $class ) . '" style="background:' . esc_attr( clfa_avatar_tone( $user_id ) ) . '">' . esc_html( $initials ?: '—' ) . '</span>';
}
