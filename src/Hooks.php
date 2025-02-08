<?php

namespace MediaWiki\Extension\AnotherDiscordNotificator;

use DatabaseLogEntry;
use LogFormatterFactory;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Json\JsonCodec;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserFactory;
use RecentChange;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

class Hooks implements RecentChange_saveHook {
	private const USER_AGENT = 'AnotherDiscordNotificator/0.1.0 (https://github.com/tesinormed/mediawiki-extensions-AnotherDiscordNotificator)';

	private Config $config;
	private TitleFormatter $titleFormatter;
	private LogFormatterFactory $logFormatterFactory;
	private IConnectionProvider $dbProvider;
	private UserFactory $userFactory;
	private JsonCodec $jsonCodec;

	public function __construct(
		ConfigFactory $configFactory,
		TitleFormatter $titleFormatter,
		LogFormatterFactory $logFormatterFactory,
		IConnectionProvider $dbProvider,
		UserFactory $userFactory,
		JsonCodec $jsonCodec
	) {
		$this->config = $configFactory->makeConfig( 'anotherdiscordnotificator' );
		$this->titleFormatter = $titleFormatter;
		$this->logFormatterFactory = $logFormatterFactory;
		$this->dbProvider = $dbProvider;
		$this->userFactory = $userFactory;
		$this->jsonCodec = $jsonCodec;
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Extension.json/Schema#callback
	 */
	public static function onRegistration( array $extensionInfo, SettingsBuilder $settings ): void {
		if ( $settings->getConfig()->get( 'AnotherDiscordNotificatorWebhook' ) === null ) {
			throw new RuntimeException( '$wgAnotherDiscordNotificatorWebhook must be set to the Discord webhook URL' );
		}
	}

	/**
	 * @inheritDoc
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/RecentChange_save
	 */
	public function onRecentChange_save( $recentChange ): void {
		if ( in_array(
			$recentChange->getAttribute( 'rc_namespace' ),
			$this->config->get( 'AnotherDiscordNotificatorDisabledNamespaces' )
		) ) {
			return;
		}
		if (
			$this->config->get( 'AnotherDiscordNotificatorIgnoreBots' )
			&& $recentChange->getAttribute( 'rc_bot' ) === 1
		) {
			return;
		}

		switch ( $recentChange->getAttribute( 'rc_source' ) ) {
			case RecentChange::SRC_EDIT:
				$webhookPayload = [ 'embeds' => [ $this->editToEmbed( $recentChange ) ] ];
				break;
			case RecentChange::SRC_NEW:
				$webhookPayload = [ 'embeds' => [ $this->newToEmbed( $recentChange ) ] ];
				break;
			case RecentChange::SRC_LOG:
				$webhookPayload = [ 'embeds' => [ $this->logToEmbed( $recentChange ) ] ];
				break;
			default:
				return;
		}

		$curlHandle = curl_init();
		curl_setopt( $curlHandle, CURLOPT_URL, $this->config->get( 'AnotherDiscordNotificatorWebhook' ) );
		curl_setopt( $curlHandle, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json; charset=utf-8' ] );
		curl_setopt( $curlHandle, CURLOPT_USERAGENT, self::USER_AGENT );
		curl_setopt( $curlHandle, CURLOPT_POST, true );
		curl_setopt( $curlHandle, CURLOPT_POSTFIELDS, $this->jsonCodec->serialize( $webhookPayload ) );
		curl_setopt( $curlHandle, CURLOPT_FOLLOWLOCATION, 1 );
		curl_setopt( $curlHandle, CURLOPT_HEADER, 0 );
		curl_setopt( $curlHandle, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $curlHandle, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $curlHandle, CURLOPT_TIMEOUT, 10 );
		curl_exec( $curlHandle );
	}

	private function editToEmbed( RecentChange $recentChange ): array {
		$title = Title::newFromPageReference( $recentChange->getPage() );
		$user = $this->userFactory->newFromUserIdentity( $recentChange->getPerformerIdentity() );

		$diffLink = $title->getFullURL( $recentChange->diffLinkTrail( forceCur: true ) );
		$histLink = $title->getFullURL( "action=history&curid={$recentChange->getAttribute( 'rc_cur_id' )}" );
		$lenDifference = $recentChange->getAttribute( 'rc_new_len' ) - $recentChange->getAttribute( 'rc_old_len' );
		$lenDifferenceText = ( $lenDifference > 0 ? '+' : '' ) . $lenDifference;
		$description = "([diff]($diffLink) | [hist]($histLink)) ($lenDifferenceText)";
		if ( $recentChange->getAttribute( 'rc_comment' ) ) {
			$description = $recentChange->getAttribute( 'rc_comment' ) . ' ' . $description;
		}

		return [
			// positive: 570888 = #08b608
			// zero: 6647148 = #656d6c
			// negative: 15802400 = #f12020
			'color' => $lenDifference > 0 ? 570888 : ( $lenDifference == 0 ? 6647148 : 15802400 ),
			'title' => $this->titleFormatter->getPrefixedText( $title ),
			'url' => $title->getFullURL(),
			'author' => [
				'name' => $user->getName(),
				'url' => $user->getUserPage()->getFullURL()
			],
			'description' => $description,
			'footer' => [
				'text' => $recentChange->getAttribute( 'rc_source' )
			],
			'timestamp' => wfTimestamp( TS_ISO_8601, $recentChange->getAttribute( 'rc_timestamp' ) )
		];
	}

	private function newToEmbed( RecentChange $recentChange ): array {
		$title = Title::newFromPageReference( $recentChange->getPage() );
		$user = $this->userFactory->newFromUserIdentity( $recentChange->getPerformerIdentity() );

		$histLink = $title->getFullURL( "action=history&curid={$recentChange->getAttribute( 'rc_cur_id' )}" );
		$description = "([hist]($histLink)) ({$recentChange->getAttribute( 'rc_new_len' )})";
		if ( $recentChange->getAttribute( 'rc_comment' ) ) {
			$description = $recentChange->getAttribute( 'rc_comment' ) . ' ' . $description;
		}

		return [
			// 6647148 = #656d6c
			'color' => 6647148,
			'title' => $this->titleFormatter->getPrefixedText( $title ),
			'url' => $title->getFullURL(),
			'author' => [
				'name' => $user->getName(),
				'url' => $user->getUserPage()->getFullURL()
			],
			'description' => $description,
			'footer' => [
				'text' => $recentChange->getAttribute( 'rc_source' )
			],
			'timestamp' => wfTimestamp( TS_ISO_8601, $recentChange->getAttribute( 'rc_timestamp' ) )
		];
	}

	private function logToEmbed( RecentChange $recentChange ): array {
		$title = Title::newFromPageReference( $recentChange->getPage() );
		$user = $this->userFactory->newFromUserIdentity( $recentChange->getPerformerIdentity() );
		$logEntry = DatabaseLogEntry::newFromId(
			$recentChange->getAttribute( 'rc_logid' ),
			$this->dbProvider->getReplicaDatabase()
		);

		$logFormatter = $this->logFormatterFactory->newFromEntry( $logEntry );
		$description = $logFormatter->getPlainActionText();
		if ( $logEntry->getComment() ) {
			$description = "$description: {$logEntry->getComment()}";
		}

		return [
			// 6647148 = #656d6c
			'color' => 6647148,
			'title' => $this->titleFormatter->getPrefixedText( $title ),
			'url' => $title->getFullURL(),
			'author' => [
				'name' => $user->getName(),
				'url' => $user->getUserPage()->getFullURL()
			],
			'description' => $description,
			'footer' => [
				'text' => $recentChange->getAttribute( 'rc_source' )
			],
			'timestamp' => wfTimestamp( TS_ISO_8601, $recentChange->getAttribute( 'rc_timestamp' ) )
		];
	}
}
