function generateMiniGraph(elem, comname, days = 30, displayName = null) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '/todays_detections.php?comname=' + encodeURIComponent(comname) + '&days=' + days);
  xhr.onload = function() {
    if (xhr.status === 200) {
      var detections = JSON.parse(xhr.responseText);
      if (typeof(window.chartWindow) !== 'undefined') {
        document.body.removeChild(window.chartWindow);
        window.chartWindow = undefined;
      }
      var chartWindow = document.createElement('div');
      chartWindow.className = 'chartdiv';
      document.body.appendChild(chartWindow);

      var canvas = document.createElement('canvas');
      canvas.width = chartWindow.offsetWidth;
      canvas.height = chartWindow.offsetHeight - 40;
      chartWindow.appendChild(canvas);

      var ctx = canvas.getContext('2d');
      var chart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: detections.map(item => item.date),
          datasets: [{
            label: 'Detections',
            data: detections.map(item => item.count),
            backgroundColor: '#9fe29b',
            borderColor: '#77c487',
            borderWidth: 1,
            lineTension: 0.3,
            pointRadius: 1,
            pointHitRadius: 10,
            trendlineLinear: {
              style: 'rgba(55, 99, 64, 0.5)',
              lineStyle: 'solid',
              width: 1.5
            }
          }]
        },
        options: {
          layout: { padding: { right: 10, bottom: 30 } },  // perchpi:chart-bottom-padding-v1 — leaves room for dropdown selector
          title: { display: true, fontSize: 16, fontStyle: 'bold', text: (displayName || 'Detections Over ') + (displayName ? ' \u00b7 ' : '') + days + 'd' },
          legend: { display: false },
          scales: {
            xAxes: [{
              display: true,
              gridLines: { display: true },
              ticks: {
                autoSkip: true,
                maxTicksLimit: 6,
                callback: value => value.substring(5)
              }
            }],
            yAxes: [{
              gridLines: { display: false },
              ticks: { beginAtZero: true, precision: 0, maxTicksLimit: 5 }
            }]
          }
        }
      });

      // perchpi:chart-positioning-v2 — positioning handled entirely by CSS

      var closeButton = document.createElement('button');
      closeButton.id = 'chartcb';
      closeButton.innerText = 'X';
      closeButton.style.position = 'absolute';
      closeButton.style.top = '5px';
      closeButton.style.right = '5px';
      closeButton.addEventListener('click', () => {
        document.body.removeChild(chartWindow);
        window.chartWindow = undefined;
      });
      chartWindow.appendChild(closeButton);

      var selector = document.createElement('select');
      [30, 180, 360, 720, 1080].forEach(opt => {
        var option = document.createElement('option');
        option.value = opt;
        option.text = opt + 'd';
        if (opt === days) option.selected = true;
        selector.appendChild(option);
      });
      selector.addEventListener('change', function() {
        generateMiniGraph(elem, comname, parseInt(this.value), displayName);
      });
      selector.style.position = 'absolute';
      selector.style.bottom = '5px';
      selector.style.left = '50%';
      selector.style.transform = 'translateX(-50%)';  // perchpi:dropdown-center-v1
      chartWindow.appendChild(selector);

      window.chartWindow = chartWindow;
    }
  };
  xhr.send();
}

// perchpi:no-scroll-dismiss-v1 — popup stays until X is clicked
