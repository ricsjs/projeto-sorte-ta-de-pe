import { useEffect, useRef, useState } from 'react'
import { api, getTokenPainel, setTokenPainel, onPainelExpirado } from './api.js'

function PainelConteudo({ eu, onSair }) {
  const [estoque, setEstoque] = useState(null)
  const [vendas, setVendas] = useState([])
  const [envios, setEnvios] = useState([])
  const [clientes, setClientes] = useState([])
  const [q, setQ] = useState('')
  const [msg, setMsg] = useState('')
  const [erro, setErro] = useState('')
  const [email, setEmail] = useState(null)
  const [emailTeste, setEmailTeste] = useState('')
  const [destino, setDestino] = useState('')       // sorteio que recebe o lote importado ('' = ativo)
  const [exportar, setExportar] = useState('')     // sorteio exportado ('' = todos)
  const fileRef = useRef()

  async function recarregar() {
    const [e, v, s, m, c] = await Promise.all([api.estoque(), api.vendas(q), api.envios(), api.emailStatus(), api.clientes()])
    setClientes(c)
    setEstoque(e); setVendas(v); setEnvios(s); setEmail(m)
  }
  useEffect(() => { recarregar().catch((e) => setErro(e.message)) }, [q])

  async function acao(fn, okMsg) {
    setErro(''); setMsg('')
    try { await fn(); if (okMsg) setMsg(okMsg); await recarregar() }
    catch (e) { setErro(e.message) }
  }

  async function importar(e) {
    const f = e.target.files[0]
    if (!f) return
    await acao(async () => {
      const r = await api.importarLote(f, destino)
      setMsg(`Lote #${r.lote_id} (${r.sorteio}): ${r.importados} importados, ${r.rejeitados.length} rejeitados` +
        (r.rejeitados.length ? ` (${r.rejeitados.map((x) => `linha ${x.linha}: ${x.motivo}`).join('; ')})` : ''))
    })
    fileRef.current.value = ''
  }

  async function testarEmail(e) {
    e.preventDefault()
    await acao(async () => {
      const r = await api.emailTeste(emailTeste)
      if (r.status === 'falha') throw new Error('Falha no envio: ' + r.detalhe)
      setMsg(r.status === 'enviado' ? `E-mail de teste enviado para ${emailTeste}` : r.detalhe)
    })
  }

  async function reenviar(v) {
    await acao(async () => {
      const r = await api.reenviar(v.id)
      if (r.email === 'falha') throw new Error(`E-mail para ${v.nome} falhou: ${r.detalhe_email}`)
      setMsg(`Reenviado para ${v.nome} · e-mail ${r.email} · WhatsApp ${r.whatsapp}`)
    })
  }

  if (!estoque) return <p>Carregando...</p>

  return (
    <>
      <div className="row" style={{ justifyContent: 'space-between', marginBottom: 18 }}>
        <div>
          <h1>Painel do operador</h1>
          <p className="sub" style={{ margin: 0 }}>Importa números, acompanha vendas, reenvia e exporta para a Capemisa.</p>
        </div>
        <div className="perfil">
          <span className="avatar" style={{ width: 38, height: 38, fontSize: '1rem' }}>{eu.nome[0]}</span>
          <div style={{ lineHeight: 1.25 }}>
            <b>{eu.nome}</b>
            <div className="sub" style={{ margin: 0, fontSize: '.8rem' }}>{eu.papel === 'admin' ? 'Administrador' : 'Operador'}</div>
          </div>
          <button className="ghost" onClick={onSair}>Sair</button>
        </div>
      </div>

      {msg && <p className="ok">{msg}</p>}
      {erro && <p className="err">{erro}</p>}

      <Sorteios estoque={estoque} admin={eu.papel === 'admin'} acao={acao} />

      <p className="sub" style={{ margin: '0 0 8px' }}>
        {estoque.sorteio_ativo ? <>Números do sorteio ativo: <b>{estoque.sorteio_ativo.nome}</b></> : 'Nenhum sorteio ativo: a loja não vende até um sorteio ser ativado.'}
      </p>
      <div className="stats">
        <div className={'stat' + (estoque.alerta ? ' alerta' : '')}>
          <div className="v">{estoque.livres}</div>
          <div className="l">livres {estoque.alerta && `· abaixo do mínimo (${estoque.minimo})`}</div>
        </div>
        <div className="stat"><div className="v">{estoque.atribuidos}</div><div className="l">atribuídos</div></div>
        <div className="stat"><div className="v">{estoque.cancelados}</div><div className="l">cancelados</div></div>
        <div className="stat"><div className="v">{estoque.total}</div><div className="l">total importado</div></div>
        <div className="stat"><div className="v">{estoque.clientes}</div><div className="l">clientes cadastrados</div></div>
      </div>

      <div className="grid">
        <div className="card">
          <h2>Importar lote da Capemisa</h2>
          <p className="sub">Planilha .xlsx ou .csv da Capemisa com as colunas <code>Número da Série</code>, <code>Número da Sorte</code>,
            {' '}<code>Código do Plano</code>, <code>Número Ordem</code> e <code>Número de Registro</code>. Só <code>Número da Sorte</code> é obrigatória.
            Exemplo: <code>backend-php/lote_exemplo_capemisa.xlsx</code>.</p>
          {estoque.sorteios.length === 0 ? <p className="sub">Cadastre um sorteio antes de importar.</p> : <>
            <label htmlFor="destino">Números vão para o sorteio</label>
            <select id="destino" value={destino} onChange={(e) => setDestino(e.target.value)}>
              <option value="">{estoque.sorteio_ativo ? `${estoque.sorteio_ativo.nome} (ativo)` : 'escolha um sorteio'}</option>
              {estoque.sorteios.filter((s) => !s.ativo).map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
            </select>
            <input id="arquivo" type="file" accept=".csv,.xlsx" ref={fileRef} onChange={importar} />
          </>}
          {estoque.lotes.length > 0 && (
            <ul style={{ margin: 0, paddingLeft: 18, fontSize: '0.85rem', color: 'var(--muted)' }}>
              {estoque.lotes.map((l) => <li key={l.id}>Lote #{l.id} · {l.sorteio_nome || 'sem sorteio'} · {l.arquivo} · {l.total} números · {l.importado_em}</li>)}
            </ul>
          )}
        </div>

        <div className="card">
          <h2>Controles</h2>
          <div className="row" style={{ marginBottom: 10 }}>
            <span>Loja: <strong>{estoque.pausada ? 'PAUSADA' : 'aberta'}</strong></span>
            <button className="ghost" onClick={() => acao(() => api.pausar(!estoque.pausada))}>
              {estoque.pausada ? 'Retomar vendas' : 'Pausar vendas'}
            </button>
          </div>
          <label htmlFor="exportar">Exportar retorno para Capemisa (CSV)</label>
          <div className="row" style={{ marginBottom: 10 }}>
            <select id="exportar" value={exportar} onChange={(e) => setExportar(e.target.value)} style={{ flex: 1, minWidth: 160, marginBottom: 0 }}>
              <option value="">Todos os sorteios</option>
              {estoque.sorteios.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}
            </select>
            <button className="ghost" onClick={() => acao(() => api.exportarCapemisa(exportar), 'Planilha da Capemisa baixada')}>Baixar</button>
          </div>
          {eu.papel === 'admin' && (
            <div className="row">
              <button className="ghost danger" onClick={() => acao(api.reset, 'Base zerada')}>Zerar POC</button>
            </div>
          )}
        </div>

        {email && (
          <div className="card">
            <h2>E-mail</h2>
            <div className="row" style={{ marginBottom: 8 }}>
              <span className={'pill ' + (email.modo === 'real' ? 'entregue' : 'aguardando_pagamento')}>
                {email.modo === 'real' ? 'envio real' : 'simulado'}
              </span>
              <span style={{ fontSize: '0.85rem', color: 'var(--muted)' }}>
                {email.modo === 'real' ? `${email.remetente} via ${email.host}:${email.port}` : 'Configure backend-php/.env para enviar de verdade'}
              </span>
            </div>
            <p className="sub" style={{ margin: '0 0 10px', fontSize: '0.85rem' }}>
              Anexo: {email.ebook_anexo || 'nenhum e-book encontrado'}
            </p>
            <form className="row" onSubmit={testarEmail}>
              <input id="email-teste" type="email" required placeholder="seu@email.com" value={emailTeste}
                onChange={(e) => setEmailTeste(e.target.value)} style={{ flex: 1, minWidth: 180, marginBottom: 0 }} />
              <button className="ghost">Enviar teste</button>
            </form>
          </div>
        )}
      </div>

      <div className="card">
        <div className="row" style={{ justifyContent: 'space-between', marginBottom: 10 }}>
          <h2 style={{ margin: 0 }}>Vendas</h2>
          <input id="busca" placeholder="buscar por nome, CPF ou número" value={q} onChange={(e) => setQ(e.target.value)}
            style={{ maxWidth: 300, marginBottom: 0 }} />
        </div>
        <div className="table-wrap">
          <table>
            <thead>
              <tr><th>#</th><th>Sorteio</th><th>Cliente</th><th>CPF</th><th>Qtd</th><th>Valor</th><th>Status</th><th>Números</th><th>Envios</th><th>Criado</th><th></th></tr>
            </thead>
            <tbody>
              {vendas.length === 0 && <tr><td colSpan="11" style={{ color: 'var(--muted)' }}>Nenhuma venda ainda. Vá na Loja e faça uma compra de teste.</td></tr>}
              {vendas.map((v) => (
                <tr key={v.id}>
                  <td>{v.id}</td>
                  <td>{v.sorteio_nome || '—'}</td>
                  <td>{v.nome}</td>
                  <td className="mono">{v.cpf}</td>
                  <td>{v.qtd}</td>
                  <td>{(v.valor ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</td>
                  <td><span className={'pill ' + v.status}>{v.status.replace('_', ' ')}</span></td>
                  <td className="mono" style={{ whiteSpace: 'normal', maxWidth: 260 }}>{v.numeros || '—'}</td>
                  <td>{v.envios}</td>
                  <td>{v.criado_em}</td>
                  <td className="row">
                    {v.numeros && v.status !== 'cancelado' && (
                      <button className="ghost" onClick={() => reenviar(v)}>Reenviar</button>
                    )}
                    {v.status !== 'cancelado' && (
                      <button className="ghost danger" onClick={() => acao(() => api.cancelar(v.id), 'Venda cancelada')}>Cancelar</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <ContaOperador eu={eu} />

      <div className="card">
        <h2>Clientes cadastrados</h2>
        <div className="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Nome</th><th>CPF</th><th>E-mail</th><th>WhatsApp</th><th>Compras</th><th>Números</th><th>Total pago</th><th>Cadastro</th></tr></thead>
            <tbody>
              {clientes.length === 0 && <tr><td colSpan="9" style={{ color: 'var(--muted)' }}>Nenhum cliente cadastrado ainda.</td></tr>}
              {clientes.map((c) => (
                <tr key={c.id}>
                  <td>{c.id}</td><td>{c.nome}</td><td className="mono">{c.cpf}</td><td>{c.email}</td><td>{c.whatsapp}</td>
                  <td>{c.compras}</td><td>{c.numeros}</td>
                  <td>{(c.total_pago ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</td><td>{c.criado_em}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="card">
        <h2>Mensagens enviadas</h2>
        <p className="sub" style={{ marginTop: -6 }}>WhatsApp ainda simulado. E-mail {email?.modo === 'real' ? 'sai de verdade' : 'simulado até configurar o SMTP'}.</p>
        {envios.length === 0 && <p className="sub">Nenhuma mensagem ainda.</p>}
        {envios.map((e) => (
          <div className="msg" key={e.id}>
            <div className="meta">
              {e.canal.toUpperCase()} → {e.destino} · {e.enviado_em} ·{' '}
              <span className={'pill ' + ({ enviado: 'entregue', simulado: 'aguardando_pagamento', falha: 'falha_envio' }[e.status] || '')}>{e.status}</span>
              {e.detalhe && <> · {e.detalhe}</>}
            </div>
            {e.mensagem}
          </div>
        ))}
      </div>
    </>
  )
}

// ------------------------------------------------------------ sorteios
const dataBr = (d) => (d ? d.split('-').reverse().join('/') : 'sem data')

function Sorteios({ estoque, admin, acao }) {
  const vazio = { nome: '', data_sorteio: '', ativar: false }
  const [novo, setNovo] = useState(vazio)
  const [editando, setEditando] = useState(null)   // { id, nome, data_sorteio }

  async function criar(e) {
    e.preventDefault()
    await acao(() => api.criarSorteio(novo), `Sorteio "${novo.nome}" cadastrado`)
    setNovo(vazio)
  }
  async function salvar(e) {
    e.preventDefault()
    await acao(() => api.editarSorteio(editando.id, editando), 'Sorteio atualizado')
    setEditando(null)
  }
  const ativar = (s) => acao(() => api.ativarSorteio(s.id), `"${s.nome}" agora é o sorteio ativo`)
  const remover = (s) => acao(() => api.removerSorteio(s.id), `Sorteio "${s.nome}" removido`)

  return (
    <div className="card">
      <h2>Sorteios</h2>
      <p className="sub" style={{ marginTop: -6 }}>Só um sorteio fica ativo por vez. As compras novas recebem números do sorteio ativo.</p>
      <div className="table-wrap">
        <table>
          <thead><tr><th>Sorteio</th><th>Data</th><th>Situação</th><th>Números</th><th>Livres</th><th>Vendidos</th><th>Vendas</th>{admin && <th></th>}</tr></thead>
          <tbody>
            {estoque.sorteios.length === 0 && <tr><td colSpan="8" style={{ color: 'var(--muted)' }}>Nenhum sorteio cadastrado.</td></tr>}
            {estoque.sorteios.map((s) => editando?.id === s.id ? (
              <tr key={s.id}>
                <td colSpan="8">
                  <form className="row" onSubmit={salvar}>
                    <input aria-label="Nome do sorteio" value={editando.nome} onChange={(e) => setEditando({ ...editando, nome: e.target.value })} required style={{ flex: 2, minWidth: 160, marginBottom: 0 }} />
                    <input aria-label="Data do sorteio" type="date" value={editando.data_sorteio || ''} onChange={(e) => setEditando({ ...editando, data_sorteio: e.target.value })} style={{ flex: 1, minWidth: 140, marginBottom: 0 }} />
                    <button className="ghost">Salvar</button>
                    <button type="button" className="ghost" onClick={() => setEditando(null)}>Cancelar</button>
                  </form>
                </td>
              </tr>
            ) : (
              <tr key={s.id}>
                <td><b>{s.nome}</b></td>
                <td>{dataBr(s.data_sorteio)}</td>
                <td><span className={'pill ' + (s.ativo ? 'entregue' : '')}>{s.ativo ? 'ativo' : 'inativo'}</span></td>
                <td>{s.total}</td><td>{s.livres}</td><td>{s.atribuidos}</td><td>{s.vendas}</td>
                {admin && (
                  <td className="row">
                    {!s.ativo && <button className="ghost" onClick={() => ativar(s)}>Ativar</button>}
                    <button className="ghost" onClick={() => setEditando({ id: s.id, nome: s.nome, data_sorteio: s.data_sorteio })}>Editar</button>
                    {!s.ativo && s.vendas === 0 && <button className="ghost danger" onClick={() => remover(s)}>Remover</button>}
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {admin && (
        <form onSubmit={criar} style={{ marginTop: 14 }}>
          <h2 style={{ fontSize: '1rem' }}>Novo sorteio</h2>
          <div className="row" style={{ alignItems: 'flex-end' }}>
            <div style={{ flex: 2, minWidth: 180 }}>
              <label htmlFor="s-nome">Nome</label>
              <input id="s-nome" value={novo.nome} onChange={(e) => setNovo({ ...novo, nome: e.target.value })} required placeholder="ex.: Sorteio de Dezembro" style={{ marginBottom: 0 }} />
            </div>
            <div style={{ flex: 1, minWidth: 140 }}>
              <label htmlFor="s-data">Data do sorteio</label>
              <input id="s-data" type="date" value={novo.data_sorteio} onChange={(e) => setNovo({ ...novo, data_sorteio: e.target.value })} style={{ marginBottom: 0 }} />
            </div>
            <label className="check" style={{ margin: 0 }}>
              <input type="checkbox" checked={novo.ativar} onChange={(e) => setNovo({ ...novo, ativar: e.target.checked })} /> ativar agora
            </label>
            <button className="primary" style={{ width: 'auto' }}>Cadastrar</button>
          </div>
          <p className="sub" style={{ margin: '8px 0 0', fontSize: '.85rem' }}>Depois de cadastrar, importe a planilha de números escolhendo este sorteio.</p>
        </form>
      )}
    </div>
  )
}

// ------------------------------------------------------------ acesso ao painel
export default function Painel() {
  const [eu, setEu] = useState(undefined)   // undefined = carregando, null = sem sessao
  useEffect(() => {
    onPainelExpirado(() => setEu(null))
    if (!getTokenPainel()) { setEu(null); return }
    api.painelEu().then(setEu).catch(() => setEu(null))
  }, [])

  async function sair() { try { await api.painelSair() } catch {} setTokenPainel(''); setEu(null) }

  if (eu === undefined) return <p className="sub">Carregando...</p>
  if (!eu) return <LoginPainel onEntrou={setEu} />
  return <PainelConteudo eu={eu} onSair={sair} />
}

function LoginPainel({ onEntrou }) {
  const [email, setEmail] = useState('')
  const [senha, setSenha] = useState('')
  const [erro, setErro] = useState('')
  const [carregando, setCarregando] = useState(false)

  async function entrar(e) {
    e.preventDefault(); setErro(''); setCarregando(true)
    try { const r = await api.painelEntrar(email, senha); setTokenPainel(r.token); onEntrou(r.operador) }
    catch (err) { setErro(err.message) } finally { setCarregando(false) }
  }

  return (
    <form className="card" onSubmit={entrar} style={{ maxWidth: 420, margin: '40px auto' }}>
      <h1>Painel do operador</h1>
      <p className="sub">Acesso restrito. Entre com o e-mail e a senha liberados pelo administrador.</p>
      <label htmlFor="op-email">E-mail</label>
      <input id="op-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoComplete="username" />
      <label htmlFor="op-senha">Senha</label>
      <input id="op-senha" type="password" value={senha} onChange={(e) => setSenha(e.target.value)} required autoComplete="current-password" />
      {erro && <p className="err">{erro}</p>}
      <button className="primary" disabled={carregando}>{carregando ? 'Entrando...' : 'Entrar no painel'}</button>
    </form>
  )
}

const VAZIO = { nome: '', email: '', senha: '', papel: 'operador' }

function ContaOperador({ eu }) {
  const [lista, setLista] = useState([])
  const [novo, setNovo] = useState(VAZIO)
  const [senhas, setSenhas] = useState({ atual: '', nova: '' })
  const [msg, setMsg] = useState(null)
  const admin = eu.papel === 'admin'
  const carregar = () => { if (admin) api.operadores().then(setLista).catch(() => {}) }
  useEffect(() => { carregar() }, [])

  async function rodar(fn, ok) {
    setMsg(null)
    try { await fn(); setMsg({ tipo: 'ok', texto: ok }); carregar() } catch (e) { setMsg({ tipo: 'err', texto: e.message }) }
  }

  return (
    <div className="card">
      <h2>{admin ? 'Acessos ao painel' : 'Minha senha'}</h2>
      {msg && <p className={msg.tipo}>{msg.texto}</p>}

      {admin && <>
        <div className="table-wrap" style={{ marginBottom: 16 }}>
          <table>
            <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Último acesso</th><th></th></tr></thead>
            <tbody>
              {lista.map((o) => (
                <tr key={o.id}>
                  <td>{o.nome}{o.id === eu.id && <span className="sub"> (você)</span>}</td>
                  <td>{o.email}</td>
                  <td><span className={`pill ${o.papel === 'admin' ? 'pago' : ''}`}>{o.papel === 'admin' ? 'Administrador' : 'Operador'}</span></td>
                  <td>{o.ultimo_acesso || '—'}</td>
                  <td>{o.id !== eu.id && <button className="ghost danger" onClick={() => rodar(() => api.removerOperador(o.id), `Acesso de ${o.nome} removido`)}>Remover</button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <form onSubmit={(e) => { e.preventDefault(); const em = novo.email; rodar(async () => { await api.criarOperador(novo); setNovo(VAZIO) }, `Acesso criado para ${em}`) }}>
          <h2 style={{ fontSize: '1rem' }}>Dar acesso a outra pessoa</h2>
          <div className="campos">
            <div><label htmlFor="n-nome">Nome</label><input id="n-nome" value={novo.nome} onChange={(e) => setNovo({ ...novo, nome: e.target.value })} required /></div>
            <div><label htmlFor="n-email">E-mail</label><input id="n-email" type="email" value={novo.email} onChange={(e) => setNovo({ ...novo, email: e.target.value })} required /></div>
            <div><label htmlFor="n-senha">Senha inicial</label><input id="n-senha" type="password" minLength={8} value={novo.senha} onChange={(e) => setNovo({ ...novo, senha: e.target.value })} required placeholder="mínimo 8 caracteres" autoComplete="new-password" /></div>
            <div>
              <label htmlFor="n-papel">Perfil</label>
              <select id="n-papel" value={novo.papel} onChange={(e) => setNovo({ ...novo, papel: e.target.value })}
                style={{ width: '100%', padding: '11px 13px', border: '1px solid var(--line)', borderRadius: 11, background: 'var(--surface)', color: 'var(--fg)', font: '1rem var(--body)', marginBottom: 14 }}>
                <option value="operador">Operador: usa o painel</option>
                <option value="admin">Administrador: também gerencia acessos</option>
              </select>
            </div>
          </div>
          <button className="ghost" style={{ marginBottom: 18 }}>Criar acesso</button>
        </form>
      </>}

      <form onSubmit={(e) => { e.preventDefault(); rodar(async () => { await api.trocarSenha(senhas.atual, senhas.nova); setSenhas({ atual: '', nova: '' }) }, 'Senha alterada') }}>
        {admin && <h2 style={{ fontSize: '1rem' }}>Trocar minha senha</h2>}
        <div className="campos">
          <div><label htmlFor="s-atual">Senha atual</label><input id="s-atual" type="password" value={senhas.atual} onChange={(e) => setSenhas({ ...senhas, atual: e.target.value })} required autoComplete="current-password" /></div>
          <div><label htmlFor="s-nova">Nova senha</label><input id="s-nova" type="password" minLength={8} value={senhas.nova} onChange={(e) => setSenhas({ ...senhas, nova: e.target.value })} required autoComplete="new-password" placeholder="mínimo 8 caracteres" /></div>
        </div>
        <button className="ghost">Salvar nova senha</button>
      </form>
    </div>
  )
}
