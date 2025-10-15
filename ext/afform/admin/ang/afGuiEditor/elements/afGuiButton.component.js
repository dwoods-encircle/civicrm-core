// https://civicrm.org/licensing
(function(angular, $, _) {
  "use strict";

  angular.module('afGuiEditor').component('afGuiButton', {
    templateUrl: '~/afGuiEditor/elements/afGuiButton.html',
    bindings: {
      node: '=',
      deleteThis: '&'
    },
    require: {
      editor: '^^afGuiEditor',
    },
    controller: function($scope, afGui) {
      var ts = $scope.ts = CRM.ts('org.civicrm.afform_admin'),
        ctrl = this;

      ctrl.$onInit = function() {
        ensureButtonKey();
      };

      // TODO: Add action selector to UI
      // $scope.actions = {
      //   "afform.submit()": ts('Submit Form')
      // };

      $scope.styles = _.transform(CRM.afGuiEditor.styles, function(styles, val, key) {
        styles['btn-' + key] = val;
      });

      // Getter/setter for ng-model
      $scope.getSetStyle = function(val) {
        if (arguments.length) {
          return afGui.modifyClasses(ctrl.node, _.keys($scope.styles), ['btn', val]);
        }
        return _.intersection(afGui.splitClass(ctrl.node['class']), _.keys($scope.styles))[0] || '';
      };

      $scope.pickIcon = function() {
        afGui.pickIcon().then(function(val) {
          ctrl.node['crm-icon'] = val;
        });
      };

      function ensureButtonKey() {
        if (!ctrl.editor || !ctrl.editor.afform || !ctrl.editor.afform.layout) {
          return;
        }
        var buttonNodes = afGui.findRecursive(ctrl.editor.afform.layout, function(item) {
            return item['data-af-button-key'];
          }) || [],
          keyCounts = _.countBy(buttonNodes, function(item) {
            return item['data-af-button-key'];
          }),
          key = ctrl.node['data-af-button-key'];

        if (key && keyCounts[key] <= 1) {
          return;
        }

        if (key && keyCounts[key]) {
          keyCounts[key]--;
          if (!keyCounts[key]) {
            delete keyCounts[key];
          }
        }

        ctrl.node['data-af-button-key'] = generateButtonKey(keyCounts);
      }

      function generateButtonKey(existingKeys) {
        var key;
        do {
          key = _.uniqueId('af-button-');
        }
        while (existingKeys[key]);
        existingKeys[key] = 1;
        return key;
      }

    }
  });

})(angular, CRM.$, CRM._);
