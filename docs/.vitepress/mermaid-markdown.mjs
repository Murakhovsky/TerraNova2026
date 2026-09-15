export function installMermaidMarkdown(md) {
  const defaultFence = md.renderer.rules.fence;

  md.renderer.rules.fence = (tokens, index, options, env, self) => {
    const token = tokens[index];
    const language = token.info.trim().split(/\s+/)[0]?.toLowerCase();

    if (language !== 'mermaid') {
      return defaultFence(tokens, index, options, env, self);
    }

    const source = Buffer.from(token.content, 'utf8').toString('base64');
    return `<MermaidDiagram source="${source}" />\n`;
  };
}
