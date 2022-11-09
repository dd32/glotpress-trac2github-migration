<?php

define( 'ABSPATH', 'wordpress/' );
define( 'WPINC', 'wp-includes' );

function get_bloginfo() {}
function __($s){ return $s; }

include ABSPATH . WPINC . '/functions.php';
include ABSPATH . WPINC . '/class-wp-error.php';
include ABSPATH . WPINC . '/load.php';

include ABSPATH . WPINC . '/plugin.php';
include ABSPATH . WPINC . '/IXR/class-IXR-client.php';
include ABSPATH . WPINC . '/class-wp-http-ixr-client.php';
include ABSPATH . WPINC . '/IXR/class-IXR-request.php';
include ABSPATH . WPINC . '/IXR/class-IXR-value.php';
include ABSPATH . WPINC . '/IXR/class-IXR-message.php';
include ABSPATH . WPINC . '/IXR/class-IXR-date.php';
include ABSPATH . WPINC . '/IXR/class-IXR-error.php';

include ABSPATH . WPINC . '/http.php';
include ABSPATH . WPINC . '/class-http.php';
include ABSPATH . WPINC . '/class-wp-http-cookie.php';
include ABSPATH . WPINC . '/class-wp-http-requests-hooks.php';
include ABSPATH . WPINC . '/class-wp-http-proxy.php';
include ABSPATH . WPINC . '/class-wp-http-response.php';
include ABSPATH . WPINC . '/class-wp-http-requests-response.php';

/**
 * Class Trac
 */
class Trac {

	/**
	 * Attributes key in RPC response array.
	 */
	const ATTRIBUTES = 3;

	/**
	 * Holds a reference to \WP_HTTP_IXR_Client.
	 *
	 * @var \WP_HTTP_IXR_Client
	 */
	protected $rpc;

	/**
	 * Trac constructor.
	 *
	 * @param string $username Trac username.
	 * @param string $password Trac password.
	 * @param string $host     Server to use.
	 * @param string $path     Path.
	 * @param int    $port     Which port to use. Default: 80.
	 * @param bool   $ssl      Whether to use SSL. Default: false.
	 */
	public function __construct( $username, $password, $host, $path = '/', $port = 80, $ssl = false ) {
		// Assume URL to $host, ignore $path, $port, $ssl.
		$this->rpc = new WP_HTTP_IXR_Client( $host );

		$http_basic_auth  = 'Basic ';
		$http_basic_auth .= base64_encode( $username . ':' . $password );

		$this->rpc->headers['Authorization'] = $http_basic_auth;
	}

	/**
	 * Queries Trac tickets.
	 *
	 * @param string $search Trac search query.
	 * @return bool|mixed
	 */
	public function ticket_query( $search ) {
		$ok = $this->rpc->query( 'ticket.query', $search );
		if ( ! $ok ) {
			return false;
		}

		return $this->rpc->getResponse();
	}

	/**
	 * Gets a specific Trac ticket.
	 *
	 * @param int $id Trac ticket id.
	 * @return [id, time_created, time_changed, attributes] or false on failure.
	 */
	public function ticket_get( $id ) {
		$ok = $this->rpc->query( 'ticket.get', $id );
		if ( ! $ok ) {
			return false;
		}

		$raw = $this->rpc->getResponse();

		$response = array(
			'id' => $raw[0],
			'_ts' => $raw[ self::ATTRIBUTES ]['time']->getISO()
		) + $raw[ self::ATTRIBUTES ];
		$response['changelog'] = $this->ticket_changelog( $id );

		unset( $response['time'], $response['changetime'] );


		return $response;
	}

	public function ticket_changelog( $id ) {
		$ok = $this->rpc->query( 'ticket.changeLog', $id );
		if ( ! $ok ) {
			var_dump( $this->rpc );
			return false;
		}

		$response = array();
		$current_item = false;
		foreach ( $this->rpc->getResponse() as $o ) {
			if ( ! $current_item || $current_item['_ts'] != $o[0]->getIso() ) {
				if ( $current_item ) {
					$response[] = $current_item;
					$current_item = false;
				}
				if ( '_' === $o[2][0] ) {
					continue;
				}
				$current_item = array(
					'_ts' => $o[0]->getIso(),
					'author' => $o[1],
					'type' => $o[2],
				);
			}

			$current_item['fields'][ $o[2] ] = $o[4];

			if ( 'comment' == $o[2] && $o[3] ) {
				$current_item['commentID'] = $o[3];
			}
			if ( 'attachment' == $o[2] ) {
				$current_item['fields']['content'] = $this->get_attachment( $id, $o[4] );
			}

		}
		if ( $current_item ) {
			$response[] = $current_item;
		}

		return $response;
	}

	function get_attachment( $ticket, $filename ) {
		$ok = $this->rpc->query( 'ticket.getAttachment', $ticket, $filename );
		if ( ! $ok ) {
			var_dump( $this->rpc );
			return false;
		}

		$content = $this->rpc->getResponse();
		if ( preg_match('~[^\x20-\x7E\t\r\n]~', $content) ) {
			return 'base64:' . base64_encode( $content );
		}
		return $content;
	}

	function get_milestones() {
		$ok = $this->rpc->query( 'ticket.getTicketFields' );
		var_dump( $ok );
		$content = $this->rpc->getResponse();
		return $content;
	}

	//listAttachments
	
}
