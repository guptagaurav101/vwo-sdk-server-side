<?php
namespace vwo;

Class Settings1{
    var $setting = [
    'sdkKey'=> 'ea87170ad94079aa190bc7c9b85d26fb',
  'campaigns'=> [
    [
        'goals'=> [
        [
            'identifier'=> 'CUSTOM',
          'id'=> 213,
          'type'=> 'CUSTOM_GOAL'
        ]
],
'variations'=> [
        [
            'id'=>1,
          'name'=> 'Control',
          'changes'=> [],
          'weight'=> 50
        ],
        [
            'id'=> 2,
          'name'=> 'Variation-1',
          'changes'=> [],
          'weight'=> 50
        ]
      ],
      'id'=> 230,
      'percentTraffic'=> 50,
      'key'=> 'DEV_TEST_1',
      'status'=> 'RUNNING',
      'type'=> 'VISUAL_AB'
    ]
  ],
  'accountId'=> 60781,
  'version'=> 1
];
}