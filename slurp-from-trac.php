<?php
set_time_limit(0);
include 'config.php';
include 'class-trac.php';


class Slurp {
    protected $trac = '';
    function __construct() {
        $this->trac = new Trac( USER, PASS, TRAC_HOST, '/', 443, true );
    }

	// Download Trac to JSON files.
    function slurp( $ticket_ids ) {
        foreach ( $ticket_ids as $id ) {
            $ticket = $this->trac->ticket_get( $id );
var_dump( $this->trac );
            file_put_contents( './data/' . $id . '.json', json_encode( $ticket, JSON_PRETTY_PRINT ) );
            echo '<h1>#' . $id . ' ' . $ticket['summary'] . '</h1>';
            var_dump( $ticket );
            echo '</hr>';
        }
    }

	// Create all the metadata on GitHub needed. Optional.
    function milestones_components_etc() {
        $fields = [];


        foreach ( glob( "./data/*.json" ) as $file ) {
            $ticket = json_decode( file_get_contents( $file ) );

            foreach( [ 'component', 'milestone', 'severity' ] as $field ) {
                $fields[ $field ][ $ticket->{$field} ] = true;

                if ( ! isset( $ticket->changelog ) ) continue;
                foreach ( $ticket->changelog as $c ) {
                    if ( isset( $c->fields->{$field} ) ) {
                        $fields[ $field ][ $c->fields->{$field} ] = true;
                    }
                }
            }
            foreach ( ['keywords' ] as $field ) {
                foreach ( preg_split( '!\s!', $ticket->{$field}) as $keyword ) {
                    $fields[ $field ][ $keyword ] = true;
                }
            }

        }
        foreach ( $fields as $field => $value ) {
            $fields[ $field ] = array_filter( array_keys( $value ), 'strlen' );
        }

        var_dump( $fields );
//* Don't do milestones, that's fine.
        // Create milestones
            // Step 1: Delete existing ones. 
            $repos = true;
          /*  while ( $repos ) {
                try {
                    $repos = $this->query_github_api( GITHUB_REPO, 'milestones?state=all' );
                } catch( Exception $e ) { $repos = false; continue; }

                foreach ( $repos as $milestone ) {
                    try {
                        $this->query_github_api( GITHUB_REPO, 'milestones/' . $milestone->number, 'repos', 'DELETE' );
                        echo "Deleted $milestone->title \n";
                    } catch( Exception $e ) {
                        var_dump( $e );
                        echo "Failed to delete $milestone->title \n";
                    }
                }
            }*/
        foreach ( $fields['milestone'] as $m ) {
            $date = $this->get_milestone_completion_date( $m );

            $payload = array(
                'title' => $m,
                'state' => $date ? 'closed' : 'open',
                'description' => $date ? "Closed on $date" : ''
            );
            if ( $date ) {
                $payload['due_on'] = $date;
            }
            try {
                $this->query_github_api( GITHUB_REPO, 'milestones', 'repos', 'POST', $payload );
                echo "Created $m \n";
            } catch( Exception $e ) {
                echo "Already exists $m \n";
            }
        }
//*/

        //*
        // Create labels
            // Step 1: Delete existing ones. 
          /*  $labels = true;
            while ( $labels ) {
                try {
                    $labels = $this->query_github_api( GITHUB_REPO, 'labels' );
                } catch( Exception $e ) { $labels = false; continue; }

                foreach ( $labels as $l ) {
                    try {
                        $this->query_github_api( GITHUB_REPO, 'labels/' . $l->name, 'repos', 'DELETE' );
                        echo "Deleted $l->name \n";
                    } catch( Exception $e ) {
                        var_dump( $e );
                        echo "Failed to delete $l->name \n";
                    }
                }
            }*/

        foreach ( [ 'component', 'severity', 'keywords' ] as $field ) {
            foreach ( $fields[ $field] as $l ) {
                $payload = array(
                    'name' => $this->blah_to_label( $l ),
                    'description' => ( $this->blah_to_label( $l ) != ucwords( $l ) ) ? $l : ''
                );

                try {
                    $this->query_github_api( GITHUB_REPO, 'labels', 'repos', 'POST', $payload );
                    echo "Created $l \n";
                } catch( Exception $e ) {
                    echo "Already exists $l \n";
                }
            }
        }
    // */

    }

    function blah_to_label( $blah ) {
        $blah = str_replace( '-', ' ', $blah );
		$blah = ucwords( $blah );

		return $blah;
    }

    function get_milestone_completion_date( $milestone ) {
        $dates = array(
            'x.y.z' => '01/01/2001 01:02:03 AM',
        );
        if ( ! isset( $dates[ $milestone ] ) ) {
            return false;
        }

        return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $dates[ $milestone ] ) );

    }

	function get_milestone( $milestone ) {
		if ( ! $milestone ) return null;
		static $milestones = array();
		if ( ! $milestones ) {
			$raw = true;
			$page = 1;
            while ( $raw ) {
                try {
					$raw = $this->query_github_api( GITHUB_REPO, 'milestones?state=all&page=' . $page++ );

					foreach ( $raw as $m ) {
						$milestones[ $m->title ] = $m->number;
					}
				} catch( Exception $e ) { $raw = false; continue; }
			}
		}

		return $milestones[ $milestone ] ?? null;
	}

    function push_to_github( $id ) {
        $ticket = json_decode( file_get_contents( "./data/$id.json" ) );

		echo "Procesing Trac {$ticket->id} {$ticket->summary} with " . count( $ticket->changelog )  . " comments\n";

  //     var_dump( $ticket );

		$this->attachments = array();

        $payload = [
            'issue' => [
                'title' => $ticket->summary . ' (Trac #' . $ticket->id .')',
                'body' => "Created by " . $this->user_to_github( $ticket->reporter ) . ":\n" . $this->convert_markdown( $ticket->description ),
				'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $ticket->_ts ) ),
				'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $ticket->_ts ) ),
                'labels' => array_values( array_filter( array_unique( array_merge(
					array_map(
						array( $this, 'blah_to_label' ),
						[
							$ticket->component,
							$ticket->severity,
							$ticket->priority,
							$ticket->version,
							$ticket->resolution,
						]
					),
					array_map(
						array( $this, 'blah_to_label' ),
						preg_split( '!\s!', $ticket->keywords )
					),
				) ) ) ),
				'milestone' => $this->get_milestone( $ticket->milestone ),
			],
			'comments' => []
		];
		foreach ( $ticket->changelog as $c ) {
	//		if ( 'comment' != $c->type ) continue; // soon.
			$message = sprintf(
				"Comment by %s:\n",
				$this->user_to_github( $c->author ),
				gmdate( 'Y-m-d H:i:s', strtotime( $c->_ts ) )
			);
			foreach ( [ 'priority', 'severity', 'component', 'milestone', 'version', 'keywords', 'status', 'resolution', 'cve', 'owner', 'cc' ] as $f ) {
				if ( isset( $c->fields->{$f} ) ) {
					$v = $c->fields->{$f};
					$fv = ucwords( $f );
					if ( 'milestone' == $f && ($milestone_number = $this->get_milestone( $v ) ) ) {
						$v = "[`$v`](../milestone/{$milestone_number})";
					} elseif ( 'keywords' == $f && $v ) {
						$vs = preg_split( '!\s!', $v);
						foreach ( $vs as $i => $v ) {
							$v = $this->blah_to_label( $v );
							$vs[$i] =  "[`$v`](../labels/" . rawurlencode( $v ) . ")";
						}
						$v = implode( ' ', $vs );
					} elseif ( 'cc' == $f || 'owner' == $f ) {
						$v = $v ? $this->user_to_github($v) : '``';
						if ( 'cc' == $f ) $fv = 'CC';
					} elseif ( 'resolution' != $f && 'status' != $f && 'cve' != $f && $v ) {
						$v = "[`$v`](../labels/" . rawurlencode( $this->blah_to_label( $v ) ) . ")";
					} else {
						if ( 'cve' == $f ) {
							$fv = 'CVE';
						}
						$v = "`$v`";
					}
					if ( '``' == $v ) {
						$message .= ' * ' . $fv . " cleared\n";
					} else {
						$message .= ' * ' . $fv . ' set to ' .  $v . "\n";
					}
				}
			}
			if ( isset( $c->fields->description ) ) {
				$message .= " * Description updated\n";
			}

			if ( 'attachment' == $c->type ) {
				// Upload a file.
				try {
				//	$res = $this->query_github_api( GITHUB_REPO, 'git/blobs', 'repos', 'POST', $blob_payload );
				//	var_dump( $res );
					if ( 'base64:' == substr( $c->fields->content, 0, 7 ) ) {
						$content = base64_decode( substr( $c->fields->content, 7 ) );
					} else {
						$content = $c->fields->content;
					}
					try {
						$res = $this->upload_file( $ticket->id, $c->fields->attachment, $c->author, $c->_ts, $content );
					} catch( Exception $e ) {
						try {
							$res = $this->upload_file( $ticket->id, microtime(1) . '-' . $c->fields->attachment, $c->author, $c->_ts, $content );
						} catch( Exception $e ) {
							throw $e;
						}
					}

					$this->attachments[ $c->fields->attachment ] = $res->content->html_url;

					$message .= " * Attachment [{$c->fields->attachment}]({$res->content->html_url}) added.\n";

				//	$message .= " * Attachment [{$c->fields->attachment}]($inlineurl) added inline.\n";
				} catch( Exception $e ) {
					echo "\t{$c->fields->attachment} failed to upload/import." . $e->getMessage() . "\n";
					$message .= " * `{$c->fields->attachment}` failed to upload.\n";
				}
				
			}

			if ( $c->fields->comment ) {
				$message .= "\n" . $c->fields->comment;
			}
			if ( isset( $c->fields->status ) && 'closed' == $c->fields->status ) {
				$payload['issue']['closed'] = true;
				$payload['issue']['closed_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $c->_ts ) );
			}
			$payload['issue']['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $c->_ts ) );

			$payload['comments'][] = array(
				'body' => trim( $this->convert_markdown( $message ) ),
				'created_at' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $c->_ts ) ),
			);
		}

	//	var_dump( $payload );
		$res = $this->query_github_api( GITHUB_REPO, 'import/issues', 'repos', 'POST', $payload, ['Accept' => 'application/vnd.github.golden-comet-preview+json'] );
		if ( isset( $res->id ) && isset( $res->status ) ) {
			echo "\tImporting {$res->url}\n";
		} else {
			var_dump( $res );
		}

	}

	function convert_markdown( $in ) {
		$out = str_replace( [
			'{{{', '}}}',
			"'''", "''",
		], [
			'```', '```',
			'_', '**'
		], $in );

		# headings 
		$out = preg_replace_callback('!^\s*(={2,})(.*)\1?\s*$!im', function($m) {
			return str_repeat('#', strlen($m[1]) ) . ' ' . trim( trim( $m[2], '=' ) );
		}, $out );

		// Links
		$out = preg_replace( '!\[(http[^ ]+) ([^\]]+)\]!i', '[$2]($1)', $out );

		// Attachment references
		$self = $this;
		$out = preg_replace_callback( '!\[attachment:"?([^\] ]+?)"?\]!i', function( $m ) use ( $self ) {
			if ( ! isset( $self->attachments[ $m[1] ] ) )
				return $m[0];

			return "[{$m[1]}](" . $self->attachments[ $m[1] ] . ")";
		}, $out );
		// That weren't linked, but should be.
		$out = preg_replace_callback( '!attachment:([^\] ]+)!i', function( $m ) use ( $self ) {
			if ( ! isset( $self->attachments[ $m[1] ] ) )
				return $m[0];

			return "[{$m[1]}](" . $self->attachments[ $m[1] ] . ")";
		}, $out );

		// Links to other tickets: Manually build those URLs and hope for the best.
		$out = preg_replace_callback( '!(\[?attachment:"?([^\] ]+?)"?:ticket:(\d+)\]?)!i', function( $m ) {
			$id = $m[3]; $filename = $m[2];
			$link = 'https://github.com/' . GITHUB_REPO . '/blob/trac-files/trac-' . $id . '/' . $filename;

			return "[{$filename}]({$link})";
		}, $out );
		$out = preg_replace_callback( '!(\[?attachment:ticket:(\d+):"?([^\] ]+?)"?\]?)!i', function( $m ) {
			$id = $m[2]; $filename = $m[3];
			$link = 'https://github.com/' . GITHUB_REPO . '/blob/trac-files/trac-' . $id . '/' . $filename;

			return "[{$filename}]({$link})";
		}, $out );

		// Links to other Comments..
		$out = preg_replace( '!(comment:\d+:ticket:\d+)!i', '`$1`', $out );
		$out = preg_replace( '!(ticket:\d+#comment:\d+)!i', '`$1`', $out );

		$out = preg_replace_callback( '!Replying to \[comment:\d+ (\w+)\]:!i', function( $m ) use ( $self ) {
			return 'Replying to ' . $self->user_to_github( $m[1] ) . ':';
		}, $out );

		// Changeset links
		$out = preg_replace( '!\[(\d+)(/([^ \]]+))?\]!', '[\[$1$2\]](http://glotpress.trac.wordpress.org/changeset/$1$2)', $out );
		$out = preg_replace( '!\br(\d+)\b!', '[\[$1$2\]](http://glotpress.trac.wordpress.org/changeset/$1)', $out );
		$out = preg_replace( '!\[core(\d+)(/([^ \]]+))?\]!', '[\[core$1$2\]](http://core.trac.wordpress.org/changeset/$1$2)', $out );

		// Core Ticket Refs
		#WP32142 #core28254.
		$out = preg_replace( '@(?<!\[)#WP(\d+)@i', '[#WP$1](http://core.trac.wordpress.org/ticket/$1)', $out );
		$out = preg_replace( '@(?<!\[)#core(\d+)@i', '[#WP$1](http://core.trac.wordpress.org/ticket/$1)', $out );

		// User References
		$out = preg_replace_callback( '!@([a-z0-9-_]+)!i', function( $m ) {
			return $this->user_to_github( $m[1] );
		}, $out );

		// Ticket Refs:
		$out = preg_replace_callback( '!(\b|\s|^)#(\d+)(\b|\s|$|\.)!', function($m) {
			if ( ! file_exists( "./data/{$m[2]}.json" ) ) {
				return $m[0];
			}
			$summary = json_decode( file_get_contents( "./data/{$m[2]}.json"))->summary;

			try {
				$search = $this->query_github_api( '', 'issues?q=' . urlencode( 'repo:' . GITHUB_REPO . ' "(Trac #' . $m[2] . ')"' ), 'search' );
				if ( $search && $search->items && strpos( $search->items[0]->title, "(Trac #{$m[2]})" ) ) {
					return $m[1] . '#' . $search->items[0]->number . $m[3];
				}
			} catch( Exception $e ) {}

			// [#11](../issues?q=is%3Aissue+"%28Trac+%2311%29")
			
			return "{$m[1]}[#{$m[2]} $summary](../issues?q=is%3Aissue+\"%28Trac+%23{$m[2]}%29\"){$m[3]}";
		}, $out );

		return $out;

	}

    function order_of_ops() {
        $ops = array(

        );
    
        foreach ( glob( "./data/*.json" ) as $file ) {
            $ticket = json_decode( file_get_contents( $file ) );
            if ( ! $ticket ) {
                continue;
            }
            $datetime = strtotime( $ticket->_ts );
            while ( isset( $ops[ "$datetime" ] ) ) {
                $datetime += 0.01;
            }
            $ops[ "$datetime" ] = [ 'ticket', $ticket->id, $ticket->_ts ];
            if ( $ticket->changelog ) {
                foreach ( $ticket->changelog as $id => $cl ) {
                    $datetime = strtotime( $cl->_ts );
                    while ( isset( $ops[ "$datetime" ] ) ) {
                        $datetime += 0.001;
                    }
                    $ops[ "$datetime" ] = [ 'changelog', $ticket->id, $id, $cl->_ts ];
                }
            }
        }

        ksort( $ops );

        foreach ( $ops as $date => $thing ) {
            echo "{$date} = " . json_encode( $thing ) . "\n";
        }

    }

	function user_to_github( $u, $with_wporg = true ) {
		$us = [
			'wporguser' => 'githubuser',
		];

		// TODO: Query profiles.w.org

		if ( isset( $us[ $u ] ) ) {
			if ( $with_wporg ) {
				return "@{$us[$u]} ([{$u} on WordPress.org](https://profiles.wordpress.org/{$u}/))";
			} else {
				return "@{$us[$u]}";
			}
		}

		return "@{$u}";
	}

	function upload_file( $ticket, $filename, $author, $date, $content ) {
		usleep( 250000 ); // Delay so multiple files don't cause issue.
		// PUT /repos/:owner/:repo/contents/:path
		return $this->query_github_api(
			GITHUB_REPO,
			'/contents/trac-' . $ticket . '/' . $filename,
			'repos',
			'PUT', 
			[
				"message" => "Adding $filename @ $date.",
				"committer" => [ "name" => $this->user_to_github( $author, false ), "email" => "$author@git.wordpress.org" ],
				"author" => [ "name" => $this->user_to_github( $author, false ), "email" => "$author@git.wordpress.org" ],
				'branch' => 'trac-files',
				"content" => base64_encode( $content )
			]
		);
	}

	function query_github_api( $repo, $endpoint = '', $namespace = 'repos', $method = 'GET', $payload = false, $headers = array() ) {
        $headers['Authorization'] = 'token ' . GITHUB_TOKEN;

        $opts = [
            'user-agent' => 'WordPress.org Trac; https://glotpress.trac.wordpress.org/',
			'headers' => $headers,
			'timeout' => 600,
		];
		$url = 'https://api.github.com/' . trim( $namespace, '/' ) . ( $repo ? '/' . trim( $repo, '/' ) : '' ) . rtrim( '/' . ltrim( $endpoint, '/' ), '/' );

        if ( 'GET' == $method ) {
            $request = wp_remote_get( $url, $opts );
        } else {
            if ( $payload ) {
                $opts['body'] = json_encode( $payload );
            }
			$opts['method'] = $method;
            $request = wp_remote_request( $url, $opts );
        }

		// TODO:
		// - No follow redirects, catch a redirect which means the source of the github has changed.
		// - Error handling, detect error returns.
		if ( ! $request ) {
			throw new Exception( 'Github API unavailable' );
		}
		if ( is_wp_error( $request ) ) {
			throw new Exception( 'Github API unavailable: ' . $request->get_error_code() . ' ' . $request->get_error_message() );
		}
		$api = json_decode( wp_remote_retrieve_body( $request ) );
		if ( ! $api && 'GET' == $method ) {
			throw new Exception( 'Github API unavailable.' );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $request ) && isset( $api->message ) ) {
			throw new Exception( sprintf(
				'Github API Error: %s %s (%s) - %s',
				wp_remote_retrieve_response_code( $request ),
                $api->message,
				$url,
				wp_remote_retrieve_body( $request )
			) );
		}
		return $api;
	}

}


// var_dump( ( new Slurp )->order_of_ops() );

// ( new Slurp )->slurp( ); return;

// ( new Slurp )->milestones_components_etc(); return;

// ( new Slurp )->push_to_github( 11 );
//return;
//( new Slurp )->push_to_github( $argv[1] ); return;

return;

$slurper = new Slurp;
// $slurper->slurp( range( 1, 10 ) ); // Download Trac tickets 1...10

// $slurper->milestones_components_etc(); // Create metadata
// Push tickets 1..10 to GitHub
foreach ( range( 1, 10 ) as $id ) {
	$slurper->push_to_github( $id );
	//sleep( 1 );
 }

//var_dump( ( new Slurp )->upload_file( 2, 'patchfile.patch', 'dd32', 'datehere', 'Index: 123' ) );

//var_dump( ( new Slurp )->query_github_api( '', 'issues?q=' . urlencode( 'repo:' . GITHUB_REPO . ' "(Trac #9)"' ), 'search' ) );
