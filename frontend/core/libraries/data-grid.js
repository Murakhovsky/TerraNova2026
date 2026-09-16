export const loadDataGrid = async () => {
  const [{ TabulatorFull }, _styles] = await Promise.all([
    import('tabulator-tables'),
    import('tabulator-tables/dist/css/tabulator.min.css'),
  ]);

  return TabulatorFull;
};
