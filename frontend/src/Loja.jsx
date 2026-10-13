import { useEffect, useState } from 'react'
import { api, getToken, setToken } from './api.js'

const brl = (v) => (v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
const cpfFmt = (c) => (c || '').replace(/\D/g, '').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
const dataFmt = (s) => (s ? new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '')
const STATUS = {
  aguardando_pagamento: 'Aguardando pagamento', pago: 'Pago, enviando', entregue: 'Entregue',
  falha_envio: 'Pago, reenvio pendente', sem_numero: 'Pago, números pendentes', cancelado: 'Cancelada',
}

export default function Loja() {
  const [status, setStatus] = useState(null)
  const [conta, setConta] = useState(undefined)   // undefined = carregando, null = deslogado
  const [venda, setVenda] = useState(null)

  const carregarStatus = () => api.lojaStatus().then(setStatus).catch(() => {})
  const carregarConta = () => {
    if (!getToken()) { setConta(null); return Promise.resolve() }
    return api.minhaConta().then(setConta).catch(() => { setToken(''); setConta(null) })
  }
  useEffect(() => { carregarStatus(); carregarConta().then(voltouDoPagamento) }, [])

  // cliente voltou da pagina da InfinitePay: confirma o pagamento e abre a compra
  function voltouDoPagamento() {
    const q = new URLSearchParams(window.location.search)
    if (!q.get('pagamento')) return
    window.history.replaceState(null, '', window.location.pathname)
    const order_nsu = q.get('order_nsu') || q.get('pagamento')
    const transaction_nsu = q.get('transaction_nsu')
    if (!getToken() || !transaction_nsu) return
    api.retornoInfinitepay({ order_nsu, transaction_nsu, slug: q.get('slug') || '' })
      .then((r) => r.venda_id && setVenda(r.venda_id))
      .catch(() => {})
  }

  async function sair() { try { await api.sair() } catch {} setToken(''); setConta(null); setVenda(null) }
  function voltar() { setVenda(null); carregarStatus(); carregarConta() }

  if (!status || conta === undefined) return <p className="sub">Carregando...</p>

  return (
    <>
      <Topo status={status} />
      {!conta && <>
        <ol className="passos">
          <li><span className="n">1</span><span><b>Crie sua conta</b>Uma vez só, com seu CPF.</span></li>
          <li><span className="n">2</span><span><b>Escolha quantos números</b>Pague tudo num único PIX.</span></li>
          <li><span className="n">3</span><span><b>Receba na hora</b>E-book e números por e-mail e WhatsApp.</span></li>
        </ol>
        <Acesso onEntrou={carregarConta} />
      </>}
      {conta && !venda && <Conta conta={conta} status={status} onSair={sair} onComprou={setVenda} onEditou={carregarConta} />}
      {conta && venda && <Compra id={venda} onVoltar={voltar} />}
    </>
  )
}

function Topo({ status }) {
  const vendidos = Math.max(0, (status.total ?? 0) - status.livres)
  return (
    <section className="card hero">
      <span className="tag">E-book + sorteio pela Loteria Federal</span>
      <h1>Seu roteiro de viagem com números da sorte</h1>
      <p>Cada número vem com o e-book do roteiro completo. Quanto mais números, mais chances no sorteio.</p>
      {status.sorteio && <p style={{ margin: '0 0 12px' }}><b>{status.sorteio.nome}</b>{status.sorteio.data_sorteio && ` · sorteio em ${status.sorteio.data_sorteio.split('-').reverse().join('/')}`}</p>}
      <div className="chips">
        <div className="chip"><b>{brl(status.valor)}</b>por número</div>
        <div className="chip"><b>{status.livres}</b>números disponíveis</div>
      </div>
      {status.total > 0 && <div className="estoque" aria-hidden="true"><i style={{ width: `${Math.min(100, (vendidos / status.total) * 100)}%` }} /></div>}
    </section>
  )
}

// ------------------------------------------------------------ cadastro e login
function Acesso({ onEntrou }) {
  const [modo, setModo] = useState('cadastro')
  const [f, setF] = useState({ nome: '', cpf: '', email: '', whatsapp: '', senha: '', login: '' })
  const [erro, setErro] = useState('')
  const [carregando, setCarregando] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  async function enviar(e) {
    e.preventDefault(); setErro(''); setCarregando(true)
    try {
      const r = modo === 'cadastro' ? await api.cadastrar(f) : await api.entrar(f.login, f.senha)
      setToken(r.token); await onEntrou()
    } catch (err) { setErro(err.message) } finally { setCarregando(false) }
  }

  return (
    <form className="card" onSubmit={enviar}>
      <div className="tabs" role="tablist">
        <button type="button" role="tab" className={modo === 'cadastro' ? 'on' : ''} onClick={() => { setModo('cadastro'); setErro('') }}>Criar conta</button>
        <button type="button" role="tab" className={modo === 'login' ? 'on' : ''} onClick={() => { setModo('login'); setErro('') }}>Já tenho conta</button>
      </div>
      {modo === 'cadastro' ? <>
        <p className="sub">O CPF identifica o titular dos números no sorteio. Depois é só entrar e comprar quantos quiser.</p>
        <div className="campos">
          <div><label htmlFor="c-nome">Nome completo</label><input id="c-nome" value={f.nome} onChange={set('nome')} required autoComplete="name" /></div>
          <div><label htmlFor="c-cpf">CPF</label><input id="c-cpf" value={f.cpf} onChange={set('cpf')} required inputMode="numeric" placeholder="000.000.000-00" /></div>
          <div><label htmlFor="c-email">E-mail</label><input id="c-email" type="email" value={f.email} onChange={set('email')} required autoComplete="email" /></div>
          <div><label htmlFor="c-wpp">WhatsApp com DDD</label><input id="c-wpp" value={f.whatsapp} onChange={set('whatsapp')} required inputMode="tel" placeholder="(11) 99999-0000" /></div>
          <div><label htmlFor="c-senha">Senha</label><input id="c-senha" type="password" value={f.senha} onChange={set('senha')} required minLength={6} autoComplete="new-password" placeholder="mínimo 6 caracteres" /></div>
        </div>
      </> : <>
        <p className="sub">Entre com o e-mail ou o CPF que você cadastrou.</p>
        <div className="campos">
          <div><label htmlFor="l-login">E-mail ou CPF</label><input id="l-login" value={f.login} onChange={set('login')} required autoComplete="username" /></div>
          <div><label htmlFor="l-senha">Senha</label><input id="l-senha" type="password" value={f.senha} onChange={set('senha')} required autoComplete="current-password" /></div>
        </div>
      </>}
      {erro && <p className="err">{erro}</p>}
      <button className="primary" disabled={carregando}>{carregando ? 'Aguarde...' : modo === 'cadastro' ? 'Criar conta e continuar' : 'Entrar'}</button>
    </form>
  )
}

function EditarCadastro({ cliente, onSalvou }) {
  const [f, setF] = useState({ whatsapp: cliente.whatsapp, nova_senha: '', confirmar: '', senha_atual: '' })
  const [erro, setErro] = useState('')
  const [carregando, setCarregando] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  async function salvar(e) {
    e.preventDefault(); setErro('')
    if (f.nova_senha !== f.confirmar) { setErro('A confirmação não bate com a nova senha'); return }
    setCarregando(true)
    try {
      await api.editarCadastro({ whatsapp: f.whatsapp, nova_senha: f.nova_senha, senha_atual: f.senha_atual })
      await onSalvou()
    } catch (err) { setErro(err.message) } finally { setCarregando(false) }
  }

  return (
    <form className="card" onSubmit={salvar}>
      <h2>Editar cadastro</h2>
      <p className="sub">Nome, CPF e e-mail não mudam por aqui. Deixe a nova senha em branco para manter a atual.</p>
      <div className="campos">
        <div><label htmlFor="e-wpp">WhatsApp com DDD</label><input id="e-wpp" value={f.whatsapp} onChange={set('whatsapp')} required inputMode="tel" /></div>
        <div><label htmlFor="e-nova">Nova senha</label><input id="e-nova" type="password" value={f.nova_senha} onChange={set('nova_senha')} minLength={6} autoComplete="new-password" placeholder="mínimo 6 caracteres" /></div>
        <div><label htmlFor="e-conf">Confirmar nova senha</label><input id="e-conf" type="password" value={f.confirmar} onChange={set('confirmar')} autoComplete="new-password" /></div>
        <div><label htmlFor="e-atual">Senha atual</label><input id="e-atual" type="password" value={f.senha_atual} onChange={set('senha_atual')} required autoComplete="current-password" placeholder="para confirmar a alteração" /></div>
      </div>
      {erro && <p className="err">{erro}</p>}
      <button className="primary" disabled={carregando}>{carregando ? 'Salvando...' : 'Salvar alterações'}</button>
    </form>
  )
}

// ------------------------------------------------------------ area do cliente
function Bilhete({ n, grande }) {
  return (
    <div className={`ticket ${grande ? 'grande' : ''} ${n.status === 'cancelado' ? 'off' : ''}`}>
      <div className="num">{n.numero}</div>
      <div className="info">{n.sorteio_nome ? `${n.sorteio_nome} · ` : ''}Série {n.serie || '-'} · {n.sorteio || '-'}{n.status === 'cancelado' && ' · cancelado'}</div>
    </div>
  )
}

function Conta({ conta, status, onSair, onComprou, onEditou }) {
  const [qtd, setQtd] = useState(1)
  const [editando, setEditando] = useState(false)
  const [erro, setErro] = useState('')
  const [carregando, setCarregando] = useState(false)
  const { cliente, compras, numeros } = conta
  const max = Math.max(1, status.max_por_compra)
  const fechada = status.pausada || status.livres === 0
  const pendentes = compras.filter((c) => c.status === 'aguardando_pagamento')
  const ajustar = (v) => setQtd(Math.min(max, Math.max(1, v)))

  async function comprar() {
    setErro(''); setCarregando(true)
    try {
      const r = await api.criarVenda(qtd)
      if (r.link_pagamento && !r.pix_copia_cola) { window.location.href = r.link_pagamento; return }
      onComprou(r.venda_id)
    } catch (err) { setErro(err.message) } finally { setCarregando(false) }
  }

  return (
    <>
      <div className="card perfil">
        <span className="avatar">{cliente.nome[0]}</span>
        <div style={{ minWidth: 0, flex: 1 }}>
          <h2 style={{ margin: 0 }}>Olá, {cliente.nome.split(' ')[0]}</h2>
          <div className="sub" style={{ margin: 0, fontSize: '.9rem' }}>CPF {cpfFmt(cliente.cpf)} · {cliente.email}</div>
        </div>
        <button className="ghost" onClick={() => setEditando(!editando)}>{editando ? 'Fechar' : 'Editar cadastro'}</button>
        <button className="ghost" onClick={onSair}>Sair</button>
      </div>

      {editando && <EditarCadastro cliente={cliente} onSalvou={async () => { await onEditou(); setEditando(false) }} />}

      <div className="card">
        <h2>Comprar números</h2>
        {fechada ? <p className="sub">{!status.sorteio ? 'Nenhum sorteio aberto no momento. Volte em breve.' : status.livres === 0 ? 'Os números deste sorteio esgotaram. Volte em breve.' : 'Vendas pausadas no momento. Volte em breve.'}</p> : <>
          <div className="row" style={{ gap: 10 }}>
            <div className="qtd">
              <button onClick={() => ajustar(qtd - 1)} aria-label="Diminuir">−</button>
              <input id="qtd" type="number" min="1" max={max} value={qtd} aria-label="Quantidade de números" onChange={(e) => ajustar(parseInt(e.target.value || '1', 10))} />
              <button onClick={() => ajustar(qtd + 1)} aria-label="Aumentar">+</button>
            </div>
            {[1, 5, 10, 20].filter((n) => n <= max).map((n) => (
              <button key={n} className={`ghost ${qtd === n ? 'on' : ''}`} onClick={() => setQtd(n)}>{n} {n === 1 ? 'número' : 'números'}</button>
            ))}
          </div>
          <div className="total">
            <span>{qtd} {qtd === 1 ? 'número' : 'números'} × {brl(status.valor)}</span>
            <strong>{brl(qtd * status.valor)}</strong>
          </div>
          {erro && <p className="err">{erro}</p>}
          <button className="primary" disabled={carregando} onClick={comprar}>{carregando ? 'Gerando PIX...' : `Gerar PIX de ${brl(qtd * status.valor)}`}</button>
          <p className="sub" style={{ margin: '10px 0 0', fontSize: '.85rem' }}>Até {max} números por compra. Pode comprar quantas vezes quiser.</p>
        </>}
      </div>

      {pendentes.length > 0 && (
        <div className="card" style={{ borderColor: 'var(--sun)' }}>
          <h2>PIX aguardando pagamento</h2>
          {pendentes.map((c) => (
            <div key={c.id} className="row" style={{ justifyContent: 'space-between', padding: '6px 0' }}>
              <span>Compra #{c.id} · {c.qtd} {c.qtd === 1 ? 'número' : 'números'} · <b>{brl(c.valor)}</b></span>
              <button className="ghost" onClick={() => onComprou(c.id)}>Ver PIX</button>
            </div>
          ))}
        </div>
      )}

      <div className="card">
        <div className="row" style={{ justifyContent: 'space-between', marginBottom: 12 }}>
          <h2 style={{ margin: 0 }}>Meus números da sorte</h2>
          <span className="pill pago">{conta.total_numeros} {conta.total_numeros === 1 ? 'número' : 'números'}</span>
        </div>
        {numeros.length === 0
          ? <p className="sub" style={{ margin: 0 }}>Você ainda não tem números. Faça sua primeira compra acima.</p>
          : <div className="bilhetes">{numeros.map((n) => <Bilhete key={`${n.venda_id}-${n.numero}`} n={n} />)}</div>}
      </div>

      {compras.length > 0 && (
        <div className="card">
          <h2>Minhas compras</h2>
          <div className="table-wrap">
            <table>
              <thead><tr><th>Compra</th><th>Data</th><th>Números</th><th>Valor</th><th>Situação</th></tr></thead>
              <tbody>
                {compras.map((c) => (
                  <tr key={c.id}>
                    <td>#{c.id}</td><td>{dataFmt(c.criado_em)}</td><td>{c.qtd}</td><td>{brl(c.valor)}</td>
                    <td><span className={`pill ${c.status}`}>{STATUS[c.status] || c.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </>
  )
}

// ------------------------------------------------------------ PIX e resultado
function Compra({ id, onVoltar }) {
  const [venda, setVenda] = useState(null)
  const [erro, setErro] = useState('')
  const [carregando, setCarregando] = useState(false)

  useEffect(() => { api.verVenda(id).then(setVenda).catch((e) => setErro(e.message)) }, [id])
  // enquanto aguarda pagamento ou a entrega terminar, consulta a compra a cada 2s
  useEffect(() => {
    if (!venda || !['aguardando_pagamento', 'pago'].includes(venda.status)) return
    const t = setInterval(() => api.verVenda(id).then(setVenda).catch(() => {}), 2000)
    return () => clearInterval(t)
  }, [venda, id])

  async function simularPagamento() {
    setCarregando(true); setErro('')
    try { await api.simularPagamento(id); setVenda(await api.verVenda(id)) } catch (err) { setErro(err.message) } finally { setCarregando(false) }
  }
  async function copiarPix() {
    try { await navigator.clipboard.writeText(venda.pix_copia_cola) } catch {}
  }

  if (erro && !venda) return <div className="card"><p className="err">{erro}</p><button className="ghost" onClick={onVoltar}>Voltar</button></div>
  if (!venda) return <p className="sub">Carregando...</p>
  const ativos = venda.numeros.filter((n) => n.status === 'atribuido')

  if (ativos.length > 0) {
    return (
      <div className="card numero-sorte">
        <span className="selo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.6"><path d="M5 12l5 5L20 7" /></svg></span>
        <h1>Pagamento confirmado!</h1>
        <p className="sub" style={{ margin: 0 }}>
          {venda.status === 'pago' && 'Enviando seus números por e-mail e WhatsApp...'}
          {venda.status === 'entregue' && `Enviamos ${ativos.length === 1 ? 'seu número' : `seus ${ativos.length} números`} e o e-book para ${venda.email}.`}
          {venda.status === 'falha_envio' && 'Seus números estão garantidos. O envio da mensagem falhou e nossa equipe vai reenviar.'}
        </p>
        <div className="bilhetes">{ativos.map((n) => <Bilhete key={n.numero} n={n} grande={ativos.length <= 4} />)}</div>
        <button className="primary" style={{ maxWidth: 340 }} onClick={onVoltar}>Ver minha conta e comprar mais</button>
      </div>
    )
  }

  if (venda.status === 'sem_numero') {
    return (
      <div className="card">
        <h1>Pagamento recebido, números pendentes</h1>
        <p className="sub">Os números acabaram no momento do seu pagamento. A equipe foi avisada e seus {venda.qtd} números serão liberados em breve.</p>
        <button className="ghost" onClick={onVoltar}>Voltar para minha conta</button>
      </div>
    )
  }

  if (venda.pagamento === 'infinitepay' || (venda.link_pagamento && !venda.pix_copia_cola)) {
    return (
      <div className="card">
        <h1>Finalize o pagamento</h1>
        <div className="total" style={{ marginTop: 10 }}>
          <span>{venda.qtd} {venda.qtd === 1 ? 'número' : 'números'} · aguardando pagamento</span>
          <strong>{brl(venda.valor)}</strong>
        </div>
        <p className="sub">O pagamento é feito na página segura da InfinitePay, por PIX ou cartão. Depois de pagar, você volta para cá e vê seus números.</p>
        <a className="primary botao-link" href={venda.link_pagamento}>Pagar com PIX ou cartão</a>
        <p className="sub" style={{ fontSize: '.88rem' }}>Já pagou? Esta tela atualiza sozinha quando o pagamento for confirmado.</p>
        <button className="ghost" onClick={onVoltar}>Voltar para minha conta</button>
      </div>
    )
  }

  return (
    <div className="card">
      <h1>Pague com PIX</h1>
      <div className="total" style={{ marginTop: 10 }}>
        <span>{venda.qtd} {venda.qtd === 1 ? 'número' : 'números'} · aguardando pagamento</span>
        <strong>{brl(venda.valor)}</strong>
      </div>
      {venda.pix_qrcode && (
        <img className="qrcode" src={`data:image/png;base64,${venda.pix_qrcode}`} alt="QR Code do PIX" />
      )}
      <label>PIX copia e cola</label>
      <div className="pix">{venda.pix_copia_cola}</div>
      <div className="row" style={{ marginTop: 10, justifyContent: 'space-between' }}>
        <button className="ghost" onClick={copiarPix}>Copiar código</button>
        <span className="sub" style={{ margin: 0, fontSize: '.88rem' }}>Esta tela atualiza sozinha quando o pagamento cair.</span>
      </div>
      {venda.pagamento === 'simulado' && (
        <div className="simulacao">
          <span className="mock">SIMULAÇÃO</span>
          <p style={{ margin: '0 0 10px', fontSize: '.9rem' }}>Pagamento real desligado. Aqui você confirma pelo botão.</p>
          <button className="primary" onClick={simularPagamento} disabled={carregando}>Simular pagamento confirmado</button>
        </div>
      )}
      {erro && <p className="err">{erro}</p>}
      <button className="ghost" onClick={onVoltar}>Voltar para minha conta</button>
    </div>
  )
}
