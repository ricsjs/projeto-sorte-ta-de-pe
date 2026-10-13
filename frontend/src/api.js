// Sessoes separadas: cliente (loja) e operador (painel)
const CLIENTE = 'cliente_token'
const OPERADOR = 'operador_token'
const ler = (k) => { try { return localStorage.getItem(k) || '' } catch { return '' } }
const gravar = (k, t) => { try { t ? localStorage.setItem(k, t) : localStorage.removeItem(k) } catch {} }

export const getToken = () => ler(CLIENTE)
export const setToken = (t) => gravar(CLIENTE, t)
export const getTokenPainel = () => ler(OPERADOR)
export const setTokenPainel = (t) => gravar(OPERADOR, t)

// avisa o painel quando a sessao do operador expira
let aoExpirarPainel = () => {}
export const onPainelExpirado = (fn) => { aoExpirarPainel = fn }

async function chamar(path, opts = {}, chave = CLIENTE) {
  const json = opts.body !== undefined && !(opts.body instanceof FormData)
  const headers = json ? { 'Content-Type': 'application/json' } : {}
  const tk = ler(chave)
  if (tk) headers.Authorization = `Bearer ${tk}`
  const r = await fetch(path, { method: opts.method, headers, body: json ? JSON.stringify(opts.body) : opts.body })
  if (!r.ok) {
    let msg = r.statusText
    try { msg = (await r.json()).detail || msg } catch {}
    if (r.status === 401 && chave === OPERADOR && !path.endsWith('/login')) { gravar(OPERADOR, ''); aoExpirarPainel() }
    const e = new Error(msg); e.status = r.status
    throw e
  }
  return opts.blob ? r.blob() : r.json()
}
const req = (p, o) => chamar(p, o, CLIENTE)
const painel = (p, o) => chamar(p, o, OPERADOR)

export const api = {
  // cliente
  cadastrar: (dados) => req('/api/clientes/cadastro', { method: 'POST', body: dados }),
  entrar: (login, senha) => req('/api/clientes/login', { method: 'POST', body: { login, senha } }),
  sair: () => req('/api/clientes/logout', { method: 'POST', body: {} }),
  minhaConta: () => req('/api/clientes/me'),
  editarCadastro: (dados) => req('/api/clientes/me', { method: 'POST', body: dados }),

  // loja
  lojaStatus: () => req('/api/loja/status'),
  criarVenda: (qtd) => req('/api/vendas', { method: 'POST', body: { qtd } }),
  verVenda: (id) => req(`/api/vendas/${id}`),
  simularPagamento: (id) => req(`/api/vendas/${id}/simular-pagamento`, { method: 'POST' }),
  retornoInfinitepay: (dados) => req('/api/pagamento/infinitepay/retorno', { method: 'POST', body: dados }),

  // acesso ao painel
  painelEntrar: (email, senha) => painel('/api/painel/login', { method: 'POST', body: { email, senha } }),
  painelSair: () => painel('/api/painel/logout', { method: 'POST', body: {} }),
  painelEu: () => painel('/api/painel/me'),
  trocarSenha: (atual, nova) => painel('/api/painel/senha', { method: 'POST', body: { atual, nova } }),
  operadores: () => painel('/api/painel/operadores'),
  criarOperador: (dados) => painel('/api/painel/operadores', { method: 'POST', body: dados }),
  removerOperador: (id) => painel(`/api/painel/operadores/${id}/remover`, { method: 'POST' }),

  // painel
  estoque: () => painel('/api/estoque'),
  importarLote: (file, sorteioId) => {
    const fd = new FormData()
    fd.append('arquivo', file)
    if (sorteioId) fd.append('sorteio_id', sorteioId)
    return painel('/api/lotes/importar', { method: 'POST', body: fd })
  },
  criarSorteio: (dados) => painel('/api/painel/sorteios', { method: 'POST', body: dados }),
  editarSorteio: (id, dados) => painel(`/api/painel/sorteios/${id}/editar`, { method: 'POST', body: dados }),
  ativarSorteio: (id) => painel(`/api/painel/sorteios/${id}/ativar`, { method: 'POST' }),
  removerSorteio: (id) => painel(`/api/painel/sorteios/${id}/remover`, { method: 'POST' }),
  vendas: (q = '') => painel(`/api/painel/vendas?q=${encodeURIComponent(q)}`),
  reenviar: (id) => painel(`/api/painel/vendas/${id}/reenviar`, { method: 'POST' }),
  cancelar: (id) => painel(`/api/painel/vendas/${id}/cancelar`, { method: 'POST' }),
  pausar: (p) => painel(`/api/painel/pausar?pausar=${p}`, { method: 'POST' }),
  envios: () => painel('/api/painel/envios'),
  clientes: () => painel('/api/painel/clientes'),
  reset: () => painel('/api/painel/reset', { method: 'POST' }),
  emailStatus: () => painel('/api/painel/email/status'),
  emailTeste: (para) => painel('/api/painel/email/teste', { method: 'POST', body: { para } }),
  // download com o token no cabecalho (um link simples nao enviaria a sessao)
  exportarCapemisa: async (sorteioId) => {
    const blob = await painel(`/api/painel/exportar-capemisa${sorteioId ? `?sorteio_id=${sorteioId}` : ''}`, { blob: true })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url; a.download = 'retorno_capemisa.csv'
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
}
