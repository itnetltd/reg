<?php
$data=\Drupal::service('reg_core.news_repository')->homepage();
foreach(array_merge([$data['primary']],$data['secondary']) as $item){echo json_encode(['id'=>$item['id'],'title'=>$item['title'],'image'=>$item['image'],'category'=>$item['category'],'date'=>$item['date']],JSON_UNESCAPED_SLASHES).PHP_EOL;}
echo json_encode(\Drupal::config('responsive_image.styles.reg_news_featured')->getRawData(),JSON_UNESCAPED_SLASHES).PHP_EOL;
echo json_encode(\Drupal::config('responsive_image.styles.reg_news_thumbnail')->getRawData(),JSON_UNESCAPED_SLASHES).PHP_EOL;