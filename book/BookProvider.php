<?php
/**
 * @author Thomas Pellissier Tanon
 * @copyright 2011 Thomas Pellissier Tanon
 * @licence http://www.gnu.org/licenses/gpl.html GNU General Public Licence
 */

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * provide all the data needed to create a book file
 */
class BookProvider {
	protected $api = null;
	protected $options = [
		'images' => true, 'fonts' => false, 'categories' => true, 'credits' => true
	];
	private $creditUrl = 'http://tools.wmflabs.org/phetools/credits.py';

	/**
	 * @var $api Api
	 */
	public function __construct( Api $api, $options ) {
		$this->api = $api;
		$this->options = array_merge( $this->options, $options );
	}

	/**
	 * return all the data on a book needed to export it
	 * @var $title string the title of the main page of the book in Wikisource
	 * @var $isMetadata bool only retrive metadata on the book
	 * @return Book
	 */
	public function get( $title, $isMetadata = false ) {
		$title = str_replace( ' ', '_', trim( $title ) );
		$doc = $this->getDocument( $title );

		return $this->getMetadata( $title, $isMetadata, $doc );
	}

	public function getMulti( array $titles, $isMetadata = false ) {
		$pages = [];
		foreach ( $titles as $title ) {
			$page = new Page();
			$page->title = str_replace( ' ', '_', trim( $title ) );
			$pages[] = $page;
		}

		$pages = $this->getPages( $pages );

		foreach ( $pages as $id => $page ) {
			$pages[$id] = $this->getMetadata( $page->title, $isMetadata, $page->content );
		}

		return $pages;
	}

	public function getMetadata( $title, $isMetadata, DOMDocument $doc ) {
		$pageList = [ $title ];
		$parser = new PageParser( $doc );
		$book = new Book();
		$book->options = $this->options;
		$book->title = $title;
		$book->lang = $this->api->lang;

		$metadataSrc = $parser->getMetadata( 'ws-metadata' );
		if ( $metadataSrc == '' ) {
			$metadataSrc = $title;
			$metadataParser = $parser;
		} else {
			$doc = $this->getDocument( $metadataSrc );
			$metadataParser = new PageParser( $doc );
		}

		$book->type = $metadataParser->getMetadata( 'ws-type' );
		$book->name = htmlspecialchars( $metadataParser->getMetadata( 'ws-title' ) );
		if ( $book->name == '' ) {
			$book->name = $this->removeNamespacesFromTitle( str_replace( '_', ' ', $metadataSrc ) );
		}
		$book->periodical = htmlspecialchars( $metadataParser->getMetadata( 'ws-periodical' ) );
		$book->author = htmlspecialchars( $metadataParser->getMetadata( 'ws-author' ) );
		$book->translator = htmlspecialchars( $metadataParser->getMetadata( 'ws-translator' ) );
		$book->illustrator = htmlspecialchars( $metadataParser->getMetadata( 'ws-illustrator' ) );
		$book->school = htmlspecialchars( $metadataParser->getMetadata( 'ws-school' ) );
		$book->publisher = htmlspecialchars( $metadataParser->getMetadata( 'ws-publisher' ) );
		$book->year = htmlspecialchars( $metadataParser->getMetadata( 'ws-year' ) );
		$book->place = htmlspecialchars( $metadataParser->getMetadata( 'ws-place' ) );
		$book->key = $metadataParser->getMetadata( 'ws-key' );
		$book->progress = $metadataParser->getMetadata( 'ws-progress' );
		$book->volume = $metadataParser->getMetadata( 'ws-volume' );
		$book->scan = str_replace( ' ', '_', $metadataParser->getMetadata( 'ws-scan' ) );
		$pictures = [];
		if ( $this->options['images'] || $isMetadata ) {
			$book->cover = $metadataParser->getMetadata( 'ws-cover' );
			if ( $book->cover != '' ) {
				$pictures[$book->cover] = $this->getCover( $book->cover, $book->lang );
				if ( $pictures[$book->cover]->url == '' ) {
					$book->cover = '';
				}
			}
		}
		if ( $this->options['categories'] ) {
			$book->categories = $this->getCategories( $metadataSrc );
		}
		$pageTitles = $parser->getPagesList();
		$namespaces = $this->getNamespaces();
		if ( !$isMetadata ) {
			if ( !$parser->metadataIsSet( 'ws-noinclude' ) ) {
				$book->content = $parser->getContent( true );
				if ( $this->options['images'] ) {
					$pictures = array_merge( $pictures, $parser->getPicturesList() );
				}
			}
			$chapterTitles = $parser->getFullChaptersList( $title, $pageList, $namespaces );
			$chapters = $this->getPages( $chapterTitles );
			foreach ( $chapters as $chapter_key => $chapter ) {
				$parser = new PageParser( $chapter->content );
				if ( $parser->metadataIsSet( 'ws-noinclude' ) ) {
					unset( $chapters[$chapter_key] );
					continue;
				}
				$pageTitles = array_merge( $pageTitles, $parser->getPagesList() );
				$chapter->content = $parser->getContent( false );
				if ( $this->options['images'] ) {
					$pictures = array_merge( $pictures, $parser->getPicturesList() );
				}
				$subpagesTitles = $parser->getChaptersList( $pageList, $namespaces );
				if ( !empty( $subpagesTitles ) ) {
					$subpages = $this->getPages( $subpagesTitles );
					foreach ( $subpages as $subpage_key => $subpage ) {
						$parser = new PageParser( $subpage->content );
						if ( $parser->metadataIsSet( 'ws-noinclude' ) ) {
							unset( $chapters[$subpage_key] );
							continue;
						}
						$pageTitles = array_merge( $pageTitles, $parser->getPagesList() );
						$subpage->content = $parser->getContent( false );
						if ( $this->options['images'] ) {
							$pictures = array_merge( $pictures, $parser->getPicturesList() );
						}
					}
					$chapterTitles = array_merge( $chapterTitles, $subpagesTitles );
					$chapter->chapters = $subpages;
				}
			}
			$book->chapters = $chapters;

			if ( $this->options['credits'] ) {
				$creditPromises = $this->startCredits( $book, $chapterTitles, $pageTitles, $pictures );
			}

			$pictures = $this->getPicturesData( $pictures );

			if ( !empty( $creditPromises ) ) {
				$book->credits = $this->finishCredit( $creditPromises );
			}
		}
		$book->pictures = $pictures;

		return $book;
	}

	/**
	 * return the content of the page
	 * @param string $title the title of the page in Wikisource
	 * @return DOMDocument
	 */
	protected function getDocument( $title ) {
		return $this->domDocumentFromHtml( $this->api->getPageAsync( $title )->wait() );
	}

	protected function domDocumentFromHtml( $html ) {
		$document = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$document->loadHTML( mb_convert_encoding( str_replace( '<?xml version="1.0" encoding="UTF-8" ?>', '', $html ), 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();
		$document->encoding = 'UTF-8';
		return $document;
	}

	/**
	 * return the content of the page
	 * @param Page[] $pages
	 * @return Page[]
	 */
	protected function getPages( $pages ) {
		$promises = [];

		foreach ( $pages as $id => $page ) {
			$promises[$id] = $this->api->getPageAsync( $page->title );
		}

		foreach ( $pages as $id => $page ) {
			$page->content = $this->domDocumentFromHtml( $promises[$id]->wait() );
		}

		return $pages;
	}

	/**
	 * return the content of the pictures
	 * @param Picture[] $pictures the list of the pictures
	 * @return Picture[]
	 */
	protected function getPicturesData( array $pictures ) {
		$this->api->getPool(
			array_map( function ( Picture $picture ) {
				return new Request( 'GET', $picture->url );
			}, $pictures ),
			[
				'fulfilled' => function ( Response $response, $index ) use ( $pictures ) {
					$content = $response->getBody()->getContents();
					$mimeType = getMimeType( $content );
					$pictures[$index]->mimetype = $mimeType;
					$pictures[$index]->content = FileCleaner::cleanFile( $content, $mimeType );
				}
			]
		)->wait();
		return $pictures;
	}

	/**
	 * return the categories in the pages
	 * @param string $title the title of the page in Wikisource
	 * @return string[] The categories
	 */
	public function getCategories( $title ) {
		$categories = [];
		$response = $this->api->query( [ 'titles' => $title, 'prop' => 'categories', 'clshow' => '!hidden' ] );
		foreach ( $response['query']['pages'] as $list ) {
			if ( isset( $list['categories'] ) ) {
				foreach ( $list['categories'] as $categorie ) {
					$cat = explode( ':', $categorie['title'], 2 );
					$categories[] = $cat[1];
				}
			}
		}

		return $categories;
	}

	/**
	 * return the cover of the book
	 * @param $cover string the name of the cover
	 * @return Picture The cover
	 */
	public function getCover( $cover, $lang ) {
		$id = explode( '/', $cover );
		$title = $id[0];
		$picture = new Picture();
		$picture->title = $cover;
		$response = $this->api->query( [ 'titles' => 'File:' . $title, 'prop' => 'imageinfo', 'iiprop' => 'mime|url|canonicaltitle' ] );
		$page = end( $response['query']['pages'] );
		$picture->url = $page['imageinfo'][0]['url'];
		$picture->mimetype = $page['imageinfo'][0]['mime'];
		if ( in_array( $picture->mimetype, [ 'image/vnd.djvu', 'application/pdf' ] ) ) {
			if ( !array_key_exists( 1, $id ) ) {
				$id[1] = 1;
			}
			$temps = explode( '/', $picture->url );
			foreach ( $temps as $temp ) {
				$title = $temp;
			}
			if ( strstr( $picture->url, '/commons/' ) ) {
				$picture->url = str_replace( 'commons/', 'commons/thumb/', $picture->url ) . '/page' . $id[1] . '-400px-' . $title . '.jpg';
			} elseif ( strstr( $picture->url, '/wikisource/' . $lang ) ) {
				$picture->url = str_replace( 'wikisource/' . $lang, 'wikisource/' . $lang . '/thumb/', $picture->url ) . '/page' . $id[1] . '-400px-' . $title . '.jpg';
			} elseif ( strstr( $picture->url, '/sources/' ) ) {
				$picture->url = str_replace( 'sources/', 'sources/thumb/', $picture->url ) . '/page' . $id[1] . '-400px-' . $title . '.jpg';
			} else {
				return new Picture();
			}
			$picture->mimetype = 'image/jpeg';
			$picture->title .= '.jpg';
			$picture->name = $page['imageinfo'][0]['canonicaltitle'];
		}

		return $picture;
	}

	/**
	 * @param Book $book
	 * @param Page[] $chapters
	 * @param string[] $otherPages
	 * @param Picture[] $pictures
	 * @return PromiseInterface[]
	 */
	protected function startCredits( Book $book, array $chapters, array $otherPages, array $pictures ) {
		$promises = [];

		$pages = [ $book->title ];
		foreach ( $chapters as $id => $chapter ) {
			$pages[] = $chapter->title;
		}
		if ( $book->scan != '' ) {
			$pages[] = 'Index:' . $book->scan;
		}
		$pages = array_unique( array_merge( $pages, $otherPages ) );
		foreach ( $this->splitArrayByBatch( $pages, 50 ) as $batch ) {
			$params = [
				'lang' => $book->lang, 'format' => 'json', 'page' => join( '|', $batch )
			];
			$promises[] = $this->api->getAsync(
				$this->creditUrl,
				[ 'query' => $params ]
			);
		}

		$imagesSet = [];
		foreach ( $pictures as $id => $picture ) {
			if ( $picture->name ) {
				$imagesSet[$picture->name] = true;
			}
		}
		if ( !empty( $imagesSet ) ) {
			$images = array_keys( $imagesSet );
			$params = [
				'lang' => $book->lang, 'format' => 'json', 'image' => join( '|', $images )
			];
			$promises[] = $this->api->getAsync(
				$this->creditUrl,
				[ 'query' => $params ]
			);
		}

		return $promises;
	}

	/**
	 * @param PromiseInterface[] $promises
	 * @return array
	 */
	public function finishCredit( $promises ) {
		$credit = [];
		foreach ( $promises as $promise ) {
			try {
				$result = json_decode( $promise->wait(), true );
			} catch ( HttpException $e ) {
				$result = [];
			}
			foreach ( $result as $name => $values ) {
				if ( !in_array( $name, $credit ) ) {
					$credit[$name] = [ 'count' => 0, 'flags' => [] ];
				}
				$credit[$name]['count'] += $values['count'];
				foreach ( $values['flags'] as $id => $flag ) {
					if ( !in_array( $flag, $credit[$name]['flags'] ) ) {
						$credit[$name]['flags'][] = $flag;
					}
				}
			}
		}

		uasort( $credit, function ( $a, $b ) {
			$f1 = in_array( 'bot', $a['flags'] );
			$f2 = in_array( 'bot', $b['flags'] );
			if ( $f1 !== $f2 ) {
				return $f1 - $f2;
			}

			return $b['count'] - $a['count'];
		} );

		return $credit;
	}

	/**
	 * return the list of the namespaces for the current wiki.
	 * @return string[]
	 */
	public function getNamespaces() {
		$namespaces = unserialize( getTempFile( $this->api->lang, 'namespaces.sphp' ) );
		if ( is_array( $namespaces ) ) {
			return $namespaces;
		} else {
			return [];
		}
	}

	private function splitArrayByBatch( $array, $limit ) {
		$result = [];
		$bagCount = $limit;
		$bagId = -1;
		foreach ( $array as $id => $value ) {
			if ( $bagCount === $limit ) {
				$bagCount = 0;
				$bagId++;
				$result[$bagId] = [];
			}
			$result[$bagId][$id] = $value;
			$bagCount++;
		}
		return $result;
	}

	private function removeNamespacesFromTitle( $title ) {
		foreach ( $this->getNamespaces() as $namespace ) {
			if ( strpos( $title, $namespace . ':' ) === 0 ) {
				return substr( $title, strlen( $namespace ) + 1 );
			}
		}
		return $title;
	}
}
