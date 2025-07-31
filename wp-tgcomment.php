<?php
/*
   Plugin Name: WP TGComment
   Author: Dmitry Krasnikov <dmitry.krasnikov@gmail.com>
   License: GPLv2 or later
   Text Domain: wp-tgcomment
   GitHub Plugin URI: https://github.com/mainpart/wp-tgcomment
   Primary Branch: main
   Domain Path: /language
   Version: 1.2.0
   Description: Плагин для интеграции комментариев с Telegram. Автоматически получает обновления от бота, обрабатывает команды пользователей, поддерживает авторизацию через Telegram Login и сохраняет входящие сообщения.
*/

/**
 * Главный файл плагина WP TGComment
 * 
 * Bootstrap файл, который:
 * - Проверяет доступность WordPress функций
 * - Загружает зависимости через Composer
 * - Определяет основные константы плагина
 * - Подключает все классы плагина
 * - Регистрирует хуки активации/деактивации
 * - Расширяет поддерживаемые MIME типы для Telegram файлов
 * - Настраивает обработку файлов без расширения
 */

// Make sure we don't expose any info if called directly
if ( ! function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

// Загрузка зависимостей Composer и установка WordPress зависимостей
include_once( __DIR__ . '/vendor/autoload.php' );
WP_Dependency_Installer::instance( __DIR__ )->run();

// Определение основных констант плагина
define( 'WP_TGCOMMENT__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_TGCOMMENT_OPTIONS', 'wp-tgcomment' );
define( 'USER_META_KEY', 'tg_login_id' );
define( 'COMMENT_ATTACHMENTS_META_KEY', 'attachment_id' );
define( 'WP_TGCOMMENT_DEBUG', true );

// Подключение всех классов плагина
require_once( WP_TGCOMMENT__PLUGIN_DIR . 'class.wp-tgcomment.php' );
require_once( WP_TGCOMMENT__PLUGIN_DIR . 'class.wp-tgcomment-handler.php' );
require_once( WP_TGCOMMENT__PLUGIN_DIR . 'class.wp-tgcomment-auth.php' );
require_once( WP_TGCOMMENT__PLUGIN_DIR . 'class.wp-tgcomment-processor.php' );
require_once( WP_TGCOMMENT__PLUGIN_DIR . 'class.wp-tgcomment-notifier.php' );

require_once( WP_TGCOMMENT__PLUGIN_DIR . 'settings.php' );

// Регистрация хуков активации и деактивации плагина
register_activation_hook( __FILE__, [ WP_TGComment::class, 'plugin_activation' ] );
register_deactivation_hook( __FILE__, [ WP_TGComment::class, 'plugin_deactivation' ] );

// Инициализация плагина
WP_TGComment::init();

/**
 * Добавление поддержки MIME типов для файлов из Telegram
 * 
 * Расширяет список разрешенных типов файлов для загрузки в WordPress
 * специально для медиафайлов, получаемых от Telegram Bot API.
 * 
 * @param array $mimes Текущий список MIME типов WordPress
 * @return array Расширенный список MIME типов
 */
add_filter( 'upload_mimes', function( $mimes ) {
	// Аудио форматы
	$mimes['ogg'] = 'audio/ogg';
	$mimes['oga'] = 'audio/ogg'; // Voice файлы из Telegram
	$mimes['opus'] = 'audio/opus';

	// Видео форматы
	$mimes['mp4'] = 'video/mp4';
	$mimes['webm'] = 'video/webm';

	// Документы
	$mimes['pdf'] = 'application/pdf';
	$mimes['doc'] = 'application/msword';
	$mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	// Неизвестные файлы и файлы без расширения
	$mimes['bin'] = 'application/octet-stream';
	// Текстовые файлы
	$mimes['txt'] = 'text/plain';

	return $mimes;
} );

/**
 * Разрешение загрузки файлов без расширения из Telegram
 * 
 * Многие файлы от Telegram API приходят без расширения или с неопределенным
 * типом. Этот фильтр использует finfo для определения реального MIME типа
 * и назначает соответствующее расширение.
 * 
 * @param array  $data     Массив с типом и расширением файла
 * @param string $file     Путь к временному файлу
 * @param string $filename Имя файла
 * @param array  $mimes    Разрешенные MIME типы
 * @return array Обновленные данные типа файла
 */
add_filter( 'wp_check_filetype_and_ext', function( $data, $file, $filename, $mimes ) {
	// Если файл уже определен правильно, оставляем как есть
	if ( $data['type'] && $data['ext'] ) {
		return $data;
	}

	// Проверяем MIME тип напрямую через finfo если доступно
	if ( function_exists( 'finfo_open' ) ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime_type = finfo_file( $finfo, $file );
		finfo_close( $finfo );

		// Мапинг MIME типов на расширения
		$mime_to_ext = [
			'audio/ogg' => 'ogg',
			'audio/opus' => 'opus',
			'video/mp4' => 'mp4',
			'video/webm' => 'webm',
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'application/pdf' => 'pdf'
		];

		if ( isset( $mime_to_ext[$mime_type] ) ) {
			$data['type'] = $mime_type;
			$data['ext'] = $mime_to_ext[$mime_type];
		}
	}

	return $data;
}, 10, 4 );
