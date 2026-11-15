<?php

declare(strict_types=1);

namespace OCA\FormVox\Tests\Unit;

use OCA\FormVox\Service\StatisticsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The response count reads the forms in personal folders, on disk and on S3 (qual#9).
 */
class StatisticsServiceTest extends TestCase {
	/**
	 * @param list<array<string, mixed>> $forms filecache rows
	 * @param list<array<string, mixed>> $storages storages rows
	 */
	private function service(array $forms, array $storages, IRootFolder $rootFolder): StatisticsService {
		$db = $this->createMock(IDBConnection::class);
		$queries = [$forms, $storages];
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$queries): IQueryBuilder {
			$rows = array_shift($queries) ?? [];
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($rows);
			$result->method('fetch')->willReturnCallback(function () use (&$rows) {
				return array_shift($rows) ?? false;
			});
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere'] as $method) {
				$qb->method($method)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		return new StatisticsService($rootFolder, $db, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class), $this->createMock(IMimeTypeLoader::class));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function homes(): array {
		return [
			'on disk' => ['home::alice'],
			'on S3' => ['object::user:alice'],
		];
	}

	/**
	 * @dataProvider homes
	 */
	public function testAFormInAPersonalFolderIsCounted(string $storageId): void {
		$form = $this->createMock(File::class);
		$form->method('getContent')->willReturn(json_encode(['_index' => ['response_count' => 3]]));
		$home = $this->createMock(Folder::class);
		$home->method('getById')->with(5)->willReturn([$form]);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($home);

		$service = $this->service([['fileid' => 5, 'storage' => 7]], [['numeric_id' => 7, 'id' => $storageId]], $rootFolder);

		$this->assertSame(3, $service->getTotalResponseCount());
	}
}
