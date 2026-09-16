let registered = false;

export const loadCharts = async () => {
  const chartModule = await import('chart.js');
  if (!registered) {
    chartModule.Chart.register(...chartModule.registerables);
    registered = true;
  }

  return chartModule.Chart;
};
