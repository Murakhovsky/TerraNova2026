export const loadSortable = async () => {
  const module = await import('sortablejs');
  return module.default;
};
