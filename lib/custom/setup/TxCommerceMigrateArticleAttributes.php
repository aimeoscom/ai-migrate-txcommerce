<?php

/**
 * @copyright Aimeos GmbH, 2015
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates article attributes from TYPO3 commerce extension
 */
class TxCommerceMigrateArticleAttributes extends TxCommerceBase
{
	private $map;
	private $size = 100;

	private $sql = '
		SELECT aa."uid_local", at."title", av."value"
		FROM "tx_commerce_articles_article_attributes_mm" aa
		JOIN "tx_commerce_articles" a ON aa."uid_local" = a."uid"
		JOIN "tx_commerce_attributes" at ON aa."uid_foreign" = at."uid"
		JOIN "tx_commerce_attribute_values" av ON aa."uid_valuelist" = av."uid"
		WHERE a."deleted" = 0 AND at."deleted" = 0 AND av."deleted" = 0
		ORDER BY aa."uid_local", aa."sorting", av."sorting"
		LIMIT ? OFFSET ?
	';


	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'TxCommerceMigrateAttributes', 'TxCommerceMigrateArticles' );
	}


	/**
	 * Executes the task for MySQL databases.
	 */
	protected function mysql()
	{
		$this->msg( 'TYPO3 commerce: Migrate article attributes', 0 ); $this->status( '' );

		if( $this->schema->tableExists( 'tx_commerce_articles_article_attributes_mm' ) === true
			&& $this->schema->tableExists( 'tx_commerce_articles' ) === true
			&& $this->schema->tableExists( 'tx_commerce_attributes' ) === true
			&& $this->schema->tableExists( 'tx_commerce_attribute_values' ) === true
		) {
			$offset = 0;
			$conn = $this->getConnection( 'db' );

			$stmt = $conn->create( $this->sql );

			do
			{
				$this->msg( 'From ' . ($offset + 1) . ' to ' . ($offset + $this->size), 1 );

				$stmt->bind( 1, $this->size, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $offset, \Aimeos\MW\DB\Statement\Base::PARAM_INT );

				$result = $stmt->execute();
				$list = $articleIds = array();
				$cnt = 0;

				while( ( $row = $result->fetch() ) !== false )
				{
					$articleIds[$row['uid_local']] = null;
					$list[] = $row;

					$cnt++;
				}

				$result->finish();

				$this->update( $list, array_keys( $articleIds ) );
				$offset += $cnt;

				$this->status( 'done' );
			}
			while( $cnt === $this->size );
		}
	}


	protected function update( array $list, array $articleIds )
	{
		$pos = 0;
		$prodMap = $this->getProductIds( $articleIds );

		$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'product/lists' );

		$listItem = $manager->createItem();
		$listItem->setTypeId( $this->getTypeId( 'product/lists/type', 'attribute', 'variant' ) );
		$listItem->setDomain( 'attribute' );
		$listItem->setStatus( 1 );

		$manager->begin();

		foreach( $list as $entry )
		{
			$attrId = $this->getAttributeId( $entry['title'], $entry['value'] );

			if( isset( $prodMap[$entry['uid_local']] ) && $attrId !== null )
			{
				if( $listItem->getParentId() != $prodMap[$entry['uid_local']] ) {
					$pos = 0;
				}

				$listItem->setId( null );
				$listItem->setRefId( $attrId );
				$listItem->setParentId( $prodMap[$entry['uid_local']] );
				$listItem->setPosition( $pos++ );

				try {
					$manager->saveItem( $listItem, false );
				} catch( \Aimeos\MW\DB\Exception $e ) {} // duplicate entry
			}
		}

		$manager->commit();
	}


	protected function getAttributeId( $type, $code )
	{
		if( !isset( $this->map ) )
		{
			$this->map = array();
			$manager = \Aimeos\MShop\Factory::createManager( $this->additional, 'attribute' );

			$search = $manager->createSearch();
			$search->setConditions( $search->compare( '==', 'attribute.domain', 'product' ) );
			$search->setSlice( 0, 0x7fffffff );

			foreach( $manager->searchItems( $search ) as $id => $item )
			{
				$this->map[$item->getType()][$item->getCode()] = $item->getId();
				unset( $item );
			}
		}

		if( isset( $this->map[$type][$code] ) ) {
			return $this->map[$type][$code];
		}
	}
}